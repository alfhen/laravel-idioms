"""Trigger eval: does a fresh headless session load the skill unprompted?

usage:
  python3 eval/trigger.py [--plugin-dir DIR] [--model haiku] [--runs 3] [--parallel 6] [--isolated]
                          [--app DIR] [--only ID,ID] [--json FILE] [--logs DIR]

Runs every prompt in eval/trigger/prompts.json --runs times with the plugin at --plugin-dir (default: this repo)
and prints, per prompt, how many runs called the Skill tool for laravel-idioms. Prompts marked `trigger` should
load the skill; prompts marked `skip` must not. The Laravel prompts run in --app (default: the committed
eval/trigger/fixture/laravel-app); the others run in their own small fixtures. Sessions are read-only: Read, Grep,
Glob and Skill are the only tools allowed, and a run stops after 6 turns, so a skill loaded later is missed.
"""
import argparse
import json
import os
import shutil
import subprocess
import tempfile
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
TRIGGER = REPO / 'eval' / 'trigger'
SKILL = 'laravel-idioms'
MAX_TURNS = 6
ALLOWED_TOOLS = 'Read,Grep,Glob,Skill'
DISALLOWED_TOOLS = 'Edit,Write,Bash,NotebookEdit,Agent,WebFetch,WebSearch'
FIXTURES = {'laravel': 'laravel-app', 'php': 'php-app', 'typescript': 'ts-app'}


def load_prompts(only=None):
    prompts = json.loads((TRIGGER / 'prompts.json').read_text())
    if only:
        unknown = set(only) - {p['id'] for p in prompts}
        if unknown:
            raise SystemExit(f"unknown prompt id(s): {', '.join(sorted(unknown))}")
        prompts = [p for p in prompts if p['id'] in only]
    return prompts


def build_command(prompt, model, plugin_dir, isolated=False):
    cmd = ['claude', '-p', prompt, '--model', model, '--max-turns', str(MAX_TURNS), '--plugin-dir', str(plugin_dir),
           '--allowedTools', ALLOWED_TOOLS, '--disallowedTools', DISALLOWED_TOOLS, '--no-session-persistence',
           '--output-format', 'stream-json', '--verbose']
    if isolated:
        cmd += ['--setting-sources', 'project,local', '--strict-mcp-config']
    return cmd


def parse_stream(text):
    """What one session's stream-json output says: whether the plugin's skill was available, which skills the
    model called, and the result event's cost and stop reason. `error` is set when the run can't be counted."""
    available, called, result = False, [], None
    for line in text.splitlines():
        try:
            event = json.loads(line)
        except ValueError:
            continue
        if not isinstance(event, dict):
            continue
        kind = event.get('type')
        if kind == 'system' and event.get('subtype') == 'init':
            available = any(SKILL in str(s) for s in event.get('skills') or [])
        elif kind == 'assistant':
            for block in (event.get('message') or {}).get('content') or []:
                if isinstance(block, dict) and block.get('type') == 'tool_use' and block.get('name') == 'Skill':
                    args = block.get('input')
                    called.append(str(args.get('skill') or '') if isinstance(args, dict) else '')
        elif kind == 'result':
            result = event
    fired = any(SKILL in name for name in called)
    # A run that hit --max-turns is a normal outcome here; any other failure says nothing about triggering.
    finished = result is not None and not result.get('api_error_status') and (
        not result.get('is_error') or result.get('subtype') == 'error_max_turns')
    error = None
    if not (fired or finished):
        if result is None:
            error = 'no result event'
        else:
            error = f"api error {result['api_error_status']}" if result.get('api_error_status') else str(result.get('subtype') or 'error')
    elif not available:
        error = f'{SKILL} skill not loaded: check --plugin-dir'
    return {'fired': fired, 'skills': called, 'available': available, 'error': error,
            'cost': float((result or {}).get('total_cost_usd') or 0), 'turns': (result or {}).get('num_turns'),
            'stop': (result or {}).get('subtype')}


def run_one(task, model, plugin_dir, isolated, logs):
    prompt, cwd, n = task['prompt'], task['cwd'], task['run']
    env = {k: v for k, v in os.environ.items() if k != 'CLAUDE_PROJECT_DIR'}
    try:
        proc = subprocess.run(build_command(prompt, model, plugin_dir, isolated), cwd=cwd, capture_output=True,
                              text=True, timeout=900, env=env)
        stdout, stderr = proc.stdout, proc.stderr
    except subprocess.TimeoutExpired:
        stdout, stderr = '', 'timed out'
    if logs:
        (logs / f"{task['id']}.{n}.jsonl").write_text(stdout)
    parsed = parse_stream(stdout)
    if parsed['error'] and stderr.strip():
        parsed['error'] += f': {stderr.strip().splitlines()[-1][:200]}'
    return {'id': task['id'], 'run': n, **parsed}


def summarise(prompts, results):
    """Per-prompt rows and the two totals. Runs with an error are counted apart and left out of every fraction."""
    rows = []
    for p in prompts:
        runs = [r for r in results if r['id'] == p['id']]
        counted = [r for r in runs if not r['error']]
        rows.append({'id': p['id'], 'expect': p['expect'], 'fired': sum(r['fired'] for r in counted),
                     'runs': len(counted), 'errors': len(runs) - len(counted), 'cost': sum(r['cost'] for r in runs)})
    totals = {}
    for expect in ('trigger', 'skip'):
        chosen = [r for r in rows if r['expect'] == expect]
        totals[expect] = {'fired': sum(r['fired'] for r in chosen), 'runs': sum(r['runs'] for r in chosen)}
    totals['errors'] = sum(r['errors'] for r in rows)
    totals['cost'] = sum(r['cost'] for r in rows)
    return rows, totals


def format_table(rows, totals):
    lines = [f"{'prompt':<18}{'expect':<9}{'fired':<8}{'errors':<8}cost"]
    for r in rows:
        lines.append(f"{r['id']:<18}{r['expect']:<9}{r['fired']}/{r['runs']:<6}{r['errors']:<8}${r['cost']:.3f}")
    t, s = totals['trigger'], totals['skip']
    lines.append('')
    lines.append(f"should trigger: {t['fired']}/{t['runs']} fired    should not: {s['fired']}/{s['runs']} fired    "
                 f"errors: {totals['errors']}    cost: ${totals['cost']:.2f}")
    return '\n'.join(lines)


def main():
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('--plugin-dir', default=str(REPO), help='plugin to test (default: this repo)')
    parser.add_argument('--model', default='sonnet')
    parser.add_argument('--runs', type=int, default=3, help='runs per prompt')
    parser.add_argument('--parallel', type=int, default=4)
    parser.add_argument('--isolated', action='store_true',
                        help='skip user-level settings and MCP servers (--setting-sources project,local --strict-mcp-config)')
    parser.add_argument('--app', help='Laravel app for the Laravel prompts (default: the committed fixture)')
    parser.add_argument('--only', help='comma-separated prompt ids to run')
    parser.add_argument('--json', metavar='FILE', help='also write per-run results and totals as JSON')
    parser.add_argument('--logs', metavar='DIR', help='keep each session\'s raw stream-json here')
    args = parser.parse_args()

    plugin_dir = Path(args.plugin_dir).resolve()
    if not (plugin_dir / '.claude-plugin' / 'plugin.json').exists():
        raise SystemExit(f'no .claude-plugin/plugin.json in {plugin_dir}')
    app = Path(args.app).resolve() if args.app else None
    if app and not (app / 'artisan').exists():
        raise SystemExit(f'no artisan file in {app}')
    prompts = load_prompts(args.only.split(',') if args.only else None)
    logs = Path(args.logs) if args.logs else None
    if logs:
        logs.mkdir(parents=True, exist_ok=True)

    # Fixtures are copied out of the repo so a session's project root is the fixture, not this checkout.
    scratch = Path(tempfile.mkdtemp(prefix='trigger-'))
    try:
        cwds = {}
        for name, directory in FIXTURES.items():
            if name == 'laravel' and app:
                cwds[name] = app
                continue
            cwds[name] = scratch / directory
            shutil.copytree(TRIGGER / 'fixture' / directory, cwds[name])
        tasks = [{**p, 'cwd': cwds[p['fixture']], 'run': n} for p in prompts for n in range(1, args.runs + 1)]
        with ThreadPoolExecutor(args.parallel) as pool:
            results = list(pool.map(lambda t: run_one(t, args.model, plugin_dir, args.isolated, logs), tasks))
    finally:
        shutil.rmtree(scratch, ignore_errors=True)

    rows, totals = summarise(prompts, results)
    print(f"plugin {plugin_dir}   model {args.model}   runs {args.runs}   "
          f"{'isolated' if args.isolated else 'full user environment'}\n")
    print(format_table(rows, totals))
    for r in results:
        if r['error']:
            print(f"error: {r['id']}.{r['run']}: {r['error']}")
    if args.json:
        Path(args.json).write_text(json.dumps({'plugin_dir': str(plugin_dir), 'model': args.model, 'runs': args.runs,
                                               'isolated': args.isolated, 'rows': rows, 'totals': totals,
                                               'results': results}, indent=1))
    raise SystemExit(1 if totals['errors'] else 0)


if __name__ == '__main__':
    main()
