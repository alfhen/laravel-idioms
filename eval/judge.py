"""Blind-judge the anonymised builds: each judge reviews every impl of one task and scores and ranks them.

usage: python3 eval/judge.py [--tasks a,b] [--judges 2] [--model opus] [--concurrency 4]
Judge N reviews the impls in a rotated order to spread position bias. Judges of one task run one after another,
because their probe tests and test runs share the task's impl directories. Results go to $WORK/judgements/.
"""
import argparse
import json
import os
import subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
WORK = Path(os.environ.get('WORK', REPO / 'eval' / '.work'))
JUDGE = Path(os.environ.get('JUDGE', WORK.parent / f'{WORK.name}-judge'))
TASKS = json.loads((REPO / 'eval' / 'tasks.json').read_text())
DIMENSIONS = ['collections_helpers', 'thin_controllers', 'validation_form_requests', 'eloquent_idioms',
              'architecture_fit', 'modern_laravel', 'focus_area', 'tests', 'correctness', 'simplicity']


def schema(impls):
    impl = {'type': 'object', 'properties': {
        'scores': {'type': 'object', 'properties': {d: {'type': 'number', 'minimum': 1, 'maximum': 10} for d in DIMENSIONS},
                   'required': DIMENSIONS},
        'overall': {'type': 'number', 'minimum': 1, 'maximum': 10},
        'rank': {'type': 'integer', 'minimum': 1, 'maximum': len(impls)},
        'tests_pass': {'type': 'boolean'},
        'bugs': {'type': 'array', 'items': {'type': 'string'}},
        'antipatterns': {'type': 'array', 'items': {'type': 'string'}},
        'strengths': {'type': 'array', 'items': {'type': 'string'}}},
        'required': ['scores', 'overall', 'rank', 'tests_pass', 'bugs', 'antipatterns', 'strengths']}
    return {'type': 'object', 'properties': {**{i: impl for i in impls}, 'rationale': {'type': 'string'}},
            'required': [*impls, 'rationale']}


def prompt(key, task, impls, order):
    base = JUDGE / 'bases' / task['base']
    root = JUDGE / 'tasks' / key
    return f"""You are a senior Laravel reviewer. {len(impls)} independent implementations of the same task are in {root}/{{{','.join(impls)}}}. They all started from the same base app {base}; see what each added with `diff -rq {base} {root}/impl-N -x vendor -x node_modules -x storage -x bootstrap -x composer.lock`. Review them in this order: {', '.join(order)}. Only look in vendor/ to verify that an API exists in the installed version.

Task they were given:
{task['text']}

For each implementation: run `php artisan test`, read every added or changed file, and probe at least two likely edge cases per implementation with a throwaway test (then delete the probe). Be thorough: small bugs and idiom differences decide the ranking. Score 1-10 on:
- collections_helpers, thin_controllers, validation_form_requests, eloquent_idioms, architecture_fit, modern_laravel (current Laravel 11-13 conventions)
- focus_area: idiomatic use of the task's focus: {task['focus']}
- tests: meaningful, idiomatic tests{' in Pest style' if task['pest'] else ''}, covering failure paths and edge cases
- correctness: meets every requirement; list real bugs you found under "bugs"
- simplicity: penalise over-engineering and ceremony
Give an overall score (holistic judgement, weighting real bugs heavily), a strict rank 1-{len(impls)} (1 = best, no ties), bugs, antipatterns with file:line, strengths. Judge the code only."""


def judge(job, model):
    key, n = job
    out = WORK / 'judgements' / f'{key}-{n}.json'
    if out.exists():
        return key, n, 'cached'
    impls = sorted(p.name for p in (JUDGE / 'tasks' / key).iterdir() if p.name.startswith('impl-'))
    shift = (n - 1) % len(impls)
    order = impls[shift:] + impls[:shift]
    cmd = ['claude', '-p', prompt(key, TASKS[key], impls, order), '--model', model, '--output-format', 'json',
           '--json-schema', json.dumps(schema(impls)), '--setting-sources', 'project,local', '--strict-mcp-config',
           '--dangerously-skip-permissions']
    result = subprocess.run(cmd, cwd=JUDGE / 'tasks' / key, capture_output=True, text=True, timeout=5400)
    envelope = json.loads(result.stdout)
    verdict = envelope.get('structured_output')
    if verdict is None:
        raise RuntimeError(f'{key}-{n}: no structured output: {result.stdout[-500:]}')
    out.write_text(json.dumps({'key': key, 'judge': n, 'v': verdict, 'cost_usd': envelope.get('total_cost_usd')}, indent=1))
    return key, n, 'ok'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--tasks', default=None)
    parser.add_argument('--judges', type=int, default=2)
    parser.add_argument('--model', default='opus')
    parser.add_argument('--concurrency', type=int, default=4)
    args = parser.parse_args()

    keys = args.tasks.split(',') if args.tasks else sorted(json.loads((WORK / 'judge-mapping.json').read_text()))
    (WORK / 'judgements').mkdir(parents=True, exist_ok=True)

    def judge_task(key):
        return [judge((key, n), args.model) for n in range(1, args.judges + 1)]

    with ThreadPoolExecutor(args.concurrency) as pool:
        for results in pool.map(judge_task, keys):
            for key, n, status in results:
                print(f'{key} judge {n}: {status}', flush=True)


if __name__ == '__main__':
    main()
