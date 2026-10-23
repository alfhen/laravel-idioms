"""Audit-mode eval: run the skill's audit mode on a fixture app with planted traps, then score the report.

usage:
  python3 eval/audit.py setup
  python3 eval/audit.py run --skill DIR --label NAME [--runs 2] [--model sonnet] [--concurrency 4]
  python3 eval/audit.py score

setup copies $WORK/arms/plain-base to $WORK/audit/fixture, overlays eval/audit/fixture/ and installs Larastan;
the fixture's tests and PHPStan (level 8) must pass before any audit runs. run starts one headless session per
run, the same way build.py does, with the skill at DIR in the project's .claude/skills/. score reads each run's
audit.json, checks every trap, applies the suggested rewrites for the traps marked `apply` to a fresh fixture
copy and reports whether PHPStan and the tests still pass.
"""
import argparse
import json
import os
import re
import shutil
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path, PureWindowsPath

REPO = Path(__file__).resolve().parent.parent
WORK = Path(os.environ.get('WORK', REPO / 'eval' / '.work'))
AUDIT = WORK / 'audit'
FIXTURE = AUDIT / 'fixture'
LARASTAN = os.environ.get('LARASTAN', 'larastan/larastan:3.13.1')
PROMPT = ('Audit this Laravel codebase: evaluate it, find bugs and code smells, and suggest refactors as a report. '
          'Also save the JSON data the report is built from as audit.json in the project root. '
          'Do not change the application code.')

# Each trap names the file it lives in; `bad` decides from a matched finding whether the audit fell for it.
# Traps a-e must not be "fixed" the wrong way; f1-f3 are real problems a good audit flags.
TRAPS = {
    'a: list<string> via array_keys(array_filter())': {
        'file': 'app/Support/LanguageSettings.php', 'kind': 'trap', 'apply': True,
        'bad': lambda f: '@var' in f['suggested'] or bool(re.search(r'->(keys|values)\(\)\s*->all\(\)', f['suggested'])
                                                            and 'array_values(' not in f['suggested'])},
    'b: strict in_array dedupe': {
        'file': 'app/Support/TagNormaliser.php', 'kind': 'trap', 'apply': True,
        'bad': lambda f: bool(re.search(r'->unique\(', f['suggested']) or re.search(r'->filter\(\)', f['suggested']))},
    'c: array_unique() on strings': {
        'file': 'app/Support/ProductCodes.php', 'kind': 'trap', 'apply': False,
        'bad': lambda f: bool(re.search(r'uniqueStrict|->unique\(', f['suggested']))},
    'd: scopeAccepted() vs attribute reads': {
        'file': 'app/Models/Offer.php', 'kind': 'trap', 'apply': True,
        'bad': lambda f: bool(re.search(r'#\[Scope\][\s\S]*function\s+accepted\s*\(', f['suggested']))},
    'e: TrimStrings stub with default $except': {
        'file': 'app/Http/Middleware/TrimStrings.php', 'kind': 'trap', 'apply': False,
        'bad': lambda f: f.get('severity') == 'high' or bool(re.search(
            r'never appl|not (be )?(exempt|applied|excluded)|passwords? (are|is|will be|get|gets) (now )?trimmed|'
            r'trims? (the |user )?passwords?', f.get('why', '') + ' ' + f.get('title', ''), re.I))},
    'f1: keyed accumulate-in-foreach': {
        'file': 'app/Reports/RevenueByCountry.php', 'kind': 'control', 'apply': False, 'bad': lambda f: False},
    'f2: private list property built in foreach': {
        'file': 'app/Support/AllowedHosts.php', 'kind': 'control', 'apply': False,
        'bad': lambda f: '@var' in f['suggested'] and bool(re.search(r'\$this->hosts\s*=\s*\$\w+\s*;', f['suggested']))},
    'f3: $request->all() into create()': {
        'file': 'app/Http/Controllers/OfferController.php', 'kind': 'control', 'apply': False,
        'bad': lambda f: False,
        'match': lambda f: '->all()' in f.get('current', '') or re.search(r'mass[- ]assign|validated\(\)', f.get('why', '') + f.get('suggested', ''), re.I)},
}


def sh(cmd, cwd, timeout=1800):
    # PHPStan and PHPUnit switch to a different output format when they detect an agent.
    env = {k: v for k, v in os.environ.items() if k not in ('AI_AGENT', 'CLAUDECODE')}
    return subprocess.run(cmd, cwd=cwd, capture_output=True, text=True, timeout=timeout, env=env)


def phpstan(app):
    out = sh(['vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--memory-limit=1G'], app)
    try:
        data = json.loads(out.stdout)
    except json.JSONDecodeError:
        return {'errors': None, 'messages': [out.stdout[-300:] or out.stderr[-300:]]}
    messages = [m['message'] for f in data['files'].values() for m in f['messages']]
    return {'errors': data['totals']['file_errors'] + data['totals']['errors'], 'messages': messages}


def tests(app):
    out = sh(['php', 'artisan', 'test'], app)
    plain = re.sub(r'\x1b\[[0-9;]*m', '', out.stdout)
    summary = [line.strip() for line in plain.splitlines() if line.strip().startswith('Tests:')]
    return {'pass': out.returncode == 0, 'summary': summary[-1] if summary else out.stdout[-200:]}


def setup():
    if FIXTURE.exists():
        shutil.rmtree(FIXTURE)
    AUDIT.mkdir(parents=True, exist_ok=True)
    subprocess.run(['cp', '-R', str(WORK / 'arms' / 'plain-base'), str(FIXTURE)], check=True)
    subprocess.run(['rsync', '-a', f"{REPO / 'eval' / 'audit' / 'fixture'}/", f'{FIXTURE}/'], check=True)
    subprocess.run(['composer', 'require', '--dev', LARASTAN, '-W', '--no-interaction', '-q'], cwd=FIXTURE, check=True)
    result, types = tests(FIXTURE), phpstan(FIXTURE)
    if not result['pass'] or types['errors'] != 0:
        raise SystemExit(f'fixture baseline is not clean: {result} {types}')
    print(f"fixture ready: {result['summary']}, PHPStan level 8: 0 errors")


def run_one(skill, label, n, model):
    runs = AUDIT / 'runs'
    dest, log, done = runs / f'{label}-{n}', runs / '_logs' / f'{label}-{n}.jsonl', runs / '_logs' / f'{label}-{n}.done'
    if done.exists():
        return label, n, 'cached'
    if dest.exists():
        shutil.rmtree(dest)
    subprocess.run(['cp', '-R', str(FIXTURE), str(dest)], check=True)
    (dest / '.claude' / 'skills').mkdir(parents=True, exist_ok=True)
    shutil.copytree(skill, dest / '.claude' / 'skills' / 'laravel-idioms')
    cmd = ['claude', '-p', PROMPT, '--model', model, '--setting-sources', 'project,local', '--strict-mcp-config',
           '--dangerously-skip-permissions', '--output-format', 'stream-json', '--verbose']
    started = time.time()
    with open(log, 'w') as fh:
        rc = subprocess.run(cmd, cwd=dest, stdout=fh, stderr=subprocess.STDOUT, timeout=5400).returncode
    done.write_text(json.dumps({'rc': rc, 'seconds': round(time.time() - started)}))
    return label, n, rc


def run(args):
    (AUDIT / 'runs' / '_logs').mkdir(parents=True, exist_ok=True)
    skill = Path(args.skill).resolve()
    if not (skill / 'SKILL.md').exists():
        raise SystemExit(f'no SKILL.md in {skill}')
    with ThreadPoolExecutor(args.concurrency) as pool:
        for label, n, rc in pool.map(lambda n: run_one(skill, args.label, n, args.model), range(1, args.runs + 1)):
            print(f'{label}-{n}: {rc}', flush=True)


def place_path(place, run_dir):
    """The repo-relative path a finding's `file` or location names, or None if it points outside the run."""
    place = str(place).replace('\\', '/').strip()
    drive = re.match(r'^[A-Za-z]:/', place)
    head, rest = (place[:2], place[2:]) if drive else ('', place)
    place = head + re.split(r'[:#(]', rest, maxsplit=1)[0].strip()
    while place.startswith('./'):
        place = place[2:]
    if drive:
        try:
            return PureWindowsPath(place).relative_to(PureWindowsPath(run_dir)).as_posix()
        except ValueError:
            return None
    if place.startswith('/'):
        try:
            return str(Path(place).resolve().relative_to(run_dir.resolve()))
        except ValueError:
            return None
    return place


def mentions(finding, path, run_dir):
    places = [finding.get('file') or '']
    for loc in finding.get('locations') or []:
        places.append(loc.get('file') or loc.get('path') or '' if isinstance(loc, dict) else loc)
    return any(place_path(place, run_dir) == path for place in places)


# The shape of a namespace import: a backslash, an alias, a group, or function/const. A bare `use HasFactory;` is
# always a trait use; split_imports() decides the rest by position.
IMPORT = re.compile(r'^(?=use\s+(function|const)\s|[^;{]*\\|[^;{]*\sas\s)use\s+((function|const)\s+)?'
                    r'[\w\\]+(\s*\{[^}]*\}|\s+as\s+\w+)?(\s*,\s*[\w\\]+(\s+as\s+\w+)?)*\s*;$')


def split_imports(suggested):
    """File-level imports: unindented `use` lines before any code. An indented `use`, or one after code (such as a
    class's opening brace), is a trait use and stays in the body."""
    imports, body = [], []
    for line in suggested.splitlines():
        leading = not code_only('\n'.join(body)).strip()
        if leading and line == line.lstrip() and IMPORT.match(line.strip()):
            imports.append(line)
        else:
            body.append(line)
    return imports, '\n'.join(body)


def code_only(source):
    """Source with comments removed; `#[...]` attributes stay."""
    source = re.sub(r'/\*.*?\*/', '', source, flags=re.S)
    return re.sub(r'(//|#(?!\[)).*$', '', source, flags=re.M)


def complete(finding):
    """A suggestion that can be applied: a `current` to find and a `suggested` with code besides imports and comments."""
    current, suggested = finding.get('current') or '', finding.get('suggested') or ''
    return bool(current.strip() and code_only(split_imports(suggested)[1]).strip())


def apply(app, path, finding):
    if not complete(finding):
        return False
    target = app / path
    text = target.read_text()
    current = finding['current']
    imports, body = split_imports(finding['suggested'])
    if current in text:
        text = text.replace(current, body, 1)
    else:
        lines, want = text.splitlines(), [line.strip() for line in current.splitlines() if line.strip()]
        stripped = [line.strip() for line in lines]
        starts = [i for i in range(len(lines)) if stripped[i] == want[0]]
        for i in starts:
            j, k = i, 0
            while j < len(lines) and k < len(want):
                if stripped[j]:
                    if stripped[j] != want[k]:
                        break
                    k += 1
                j += 1
            if k == len(want):
                text = '\n'.join(lines[:i] + body.splitlines() + lines[j:]) + '\n'
                break
        else:
            return False
    for line in imports:
        if line.strip() not in text:
            text = re.sub(r'^(namespace [^;]+;\n)', lambda m: m[1] + '\n' + line.strip(), text, count=1, flags=re.M)
    target.write_text(text)
    return True


def applied_verdict(applied, incomplete):
    """Verdict for a flagged-but-not-bad apply trap, from what happened when its rewrites were applied."""
    broken = [a for a in applied if a['applied'] and (a['phpstan']['errors'] or not a['tests']['pass'])]
    if broken:
        return 'FAIL (rewrite breaks ' + ' and '.join(sorted(
            {'PHPStan' for a in broken if a['phpstan']['errors']} | {'tests' for a in broken if not a['tests']['pass']})) + ')'
    if not all(a['applied'] for a in applied):
        return 'UNVERIFIED (rewrite not applicable)'
    if any(a['phpstan']['errors'] is None for a in applied):
        return 'pass (flagged safely, not verified: PHPStan result unavailable)'
    if incomplete:
        return f"pass (flagged safely, not verified: no complete suggestion in {', '.join(map(str, incomplete))})"
    return 'pass (flagged safely, verified)'


def score(_args):
    rows = []
    for log in sorted((AUDIT / 'runs' / '_logs').glob('*.done')):
        name = log.stem
        report = AUDIT / 'runs' / name / 'audit.json'
        row = {'run': name, 'traps': {}, 'findings': None}
        if not report.exists():
            row['error'] = 'no audit.json'
            rows.append(row)
            continue
        findings = [{**f, **{k: f.get(k) or '' for k in ('current', 'suggested', 'why', 'title')}}
                    for f in json.loads(report.read_text()).get('findings') or []]
        row['findings'] = len(findings)
        for trap, spec in TRAPS.items():
            hits = [f for f in findings if mentions(f, spec['file'], AUDIT / 'runs' / name) and spec.get('match', lambda f: True)(f)]
            bad = [f for f in hits if spec['bad'](f)]
            if spec['kind'] == 'trap':
                verdict = 'FAIL' if bad else ('pass (flagged safely)' if hits else 'pass (left alone)')
            else:
                verdict = 'FAIL' if bad else ('pass' if hits else 'missed')
            entry = {'verdict': verdict, 'findings': [f.get('id') for f in hits],
                     'evidence': [{k: f.get(k) for k in ('id', 'title', 'severity', 'why', 'suggested')} for f in hits]}
            if spec['apply'] and hits:
                incomplete = [f.get('id') for f in hits if not complete(f)]
                applied = []
                for f in filter(complete, hits):
                    copy = AUDIT / 'apply' / f"{name}-{f.get('id')}"
                    if copy.exists():
                        shutil.rmtree(copy)
                    copy.parent.mkdir(parents=True, exist_ok=True)
                    subprocess.run(['cp', '-R', str(FIXTURE), str(copy)], check=True)
                    if not apply(copy, spec['file'], f):
                        applied.append({'id': f.get('id'), 'applied': False})
                        continue
                    applied.append({'id': f.get('id'), 'applied': True, 'phpstan': phpstan(copy), 'tests': tests(copy)})
                entry['applied'] = applied
                if not bad:
                    entry['verdict'] = applied_verdict(applied, incomplete)
            row['traps'][trap] = entry
        rows.append(row)
    (AUDIT / 'scores.json').write_text(json.dumps(rows, indent=1))
    names = [r['run'] for r in rows]
    print('| Trap | ' + ' | '.join(names) + ' |\n|---|' + '---|' * len(names))
    for trap in TRAPS:
        cells = []
        for r in rows:
            e = r['traps'].get(trap)
            if not e:
                cells.append(r.get('error', '-'))
                continue
            cell = e['verdict']
            for a in e.get('applied', []):
                if a['applied']:
                    errors = 'n/a' if a['phpstan']['errors'] is None else f"{a['phpstan']['errors']} err"
                    cell += f"; {a['id']}: PHPStan {errors}, tests {'pass' if a['tests']['pass'] else 'FAIL'}"
                else:
                    cell += f"; {a['id']}: not applicable"
            cells.append(cell)
        print(f'| {trap} | ' + ' | '.join(cells) + ' |')


def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest='cmd', required=True)
    sub.add_parser('setup')
    r = sub.add_parser('run')
    r.add_argument('--skill', required=True)
    r.add_argument('--label', required=True)
    r.add_argument('--runs', type=int, default=2)
    r.add_argument('--model', default='sonnet')
    r.add_argument('--concurrency', type=int, default=4)
    sub.add_parser('score')
    args = parser.parse_args()
    {'setup': lambda a: setup(), 'run': run, 'score': score}[args.cmd](args)


if __name__ == '__main__':
    main()
