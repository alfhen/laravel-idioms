"""Count checklist violations in every build with greps and Pint. No LLM is involved.

usage: python3 eval/static.py [--tasks a,b] [--arms prev,skill]
Each check is a regex over the build's own files (app/, routes/, resources/views/, tests/, config/). The table
shows the mean count per build for each arm, so a rise in any row is a regression to look at, not a verdict:
some hits are legitimate. Results go to $WORK/static-results.json.
"""
import argparse
import json
import os
import re
import statistics
import subprocess
from collections import defaultdict
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
WORK = Path(os.environ.get('WORK', REPO / 'eval' / '.work'))
BUILD = re.compile(r'^(?P<task>.+)-(?P<arm>[a-z]+)(?:-(?P<run>\d+))?$')

# (name, directory glob prefix, regex). Directories are relative to the build root.
CHECKS = [
    ('request->all() persisted', 'app', r'\$request->all\(\)'),
    ('DB:: in controllers', 'app/Http/Controllers', r'\bDB::'),
    ('private helper in controller', 'app/Http/Controllers', r'private function'),
    ('abort_unless ownership in controller', 'app/Http/Controllers', r'abort_(unless|if)\(.*(user_id|->id\(\))'),
    ('validate() inline', 'app/Http/Controllers', r'->validate\(\['),
    ('class constants for states', 'app/Models', r'const [A-Z_]+ = \'[a-z_]+\';'),
    ('$dates / $casts property', 'app/Models', r'protected \$(dates|casts) ='),
    ('accumulate-in-foreach', 'app', r'\$\w+\[\] = '),
    ('&$ in closure', 'app', r'use \([^)]*&\$'),
    ('number_format money', 'app', r'number_format\('),
    ('{!! !!} in views', 'resources/views', r'\{!!'),
    ('query in view', 'resources/views', r'::(query|where|all|find)\('),
    ('Cache::put without TTL', 'app', r'Cache::(forever|put\([^,()]+,[^,()]+\))'),
    ('Cache::flush', 'app', r'Cache::flush\('),
    ('Filament v3 namespace', 'app', r'Filament\\(Forms\\Form\b|Tables\\Actions\\)'),
    ('env() outside config', 'app', r'\benv\('),
    ('bare withoutOverlapping()', 'routes', r'withoutOverlapping\(\)'),
    ('hardcoded redis store', 'app', r"useCache\('redis'\)|Cache::store\('redis'\)|, 'redis'\)"),
    ('chunk() (not ById)', 'app', r'->chunk\('),
    ('static Pest closure', 'tests', r'(it|test)\([^,]+, static fn'),
    ('raw FK arrays in tests', 'tests', r"'\w+_id' => \$\w+->id"),
]


def files(build, prefix):
    root = build / prefix
    if not root.exists():
        return []
    return [p for p in root.rglob('*.php') if 'vendor' not in p.parts and '/Hidden/' not in str(p)]


def scan(build):
    counts = {}
    for name, prefix, pattern in CHECKS:
        rx = re.compile(pattern)
        counts[name] = sum(len(rx.findall(p.read_text(errors='ignore'))) for p in files(build, prefix))
    pint = build / 'vendor' / 'bin' / 'pint'
    if pint.exists():
        out = subprocess.run([str(pint), '--test', '--format=json', 'app', 'routes', 'tests', 'database'], cwd=build,
                             capture_output=True, text=True).stdout
        try:
            counts['pint files to fix'] = len(json.loads(out).get('files', []))
        except json.JSONDecodeError:
            counts['pint files to fix'] = None
    return counts


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--tasks', default=None)
    parser.add_argument('--arms', default=None)
    args = parser.parse_args()
    tasks = set(args.tasks.split(',')) if args.tasks else None
    arms = args.arms.split(',') if args.arms else None

    results = {}
    for build in sorted((WORK / 'runs').iterdir()):
        match = BUILD.match(build.name)
        if not match or build.name.startswith('_'):
            continue
        if (tasks and match['task'] not in tasks) or (arms and match['arm'] not in arms):
            continue
        results[build.name] = {'task': match['task'], 'arm': match['arm'], 'counts': scan(build)}
    (WORK / 'static-results.json').write_text(json.dumps(results, indent=1))

    seen_arms = arms or sorted({r['arm'] for r in results.values()})
    per = defaultdict(lambda: defaultdict(list))
    for r in results.values():
        for name, value in r['counts'].items():
            if value is not None:
                per[name][r['arm']].append(value)
    print('| Check (mean per build) | ' + ' | '.join(seen_arms) + ' |\n|---|' + '---|' * len(seen_arms))
    for name in [c[0] for c in CHECKS] + ['pint files to fix']:
        cells = [f'{statistics.mean(per[name][a]):.2f}' if per[name][a] else '–' for a in seen_arms]
        print(f'| {name} | ' + ' | '.join(cells) + ' |')
    print('| builds | ' + ' | '.join(str(sum(r['arm'] == a for r in results.values())) for a in seen_arms) + ' |')


if __name__ == '__main__':
    main()
