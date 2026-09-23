"""Run each (task, arm) build as a headless Claude Code session inside a fresh copy of that arm's app.

usage: python3 eval/build.py [--arms plain,skill,boost,both,augment] [--tasks a,b] [--model sonnet] [--concurrency 6]
"""
import argparse
import json
import os
import shutil
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
WORK = Path(os.environ.get('WORK', REPO / 'eval' / '.work'))
TASKS = json.loads((REPO / 'eval' / 'tasks.json').read_text())
MCP_ARMS = {'boost', 'both', 'augment'}


def prompt(task):
    tests = ('The project uses Pest. Write Pest feature tests covering the behaviour.'
             if task['pest'] else 'Write feature tests (PHPUnit, as the skeleton uses) covering the behaviour.')
    return (f"{task['text']}\n\n{tests} The test DB is SQLite in-memory. The app and its tests must not make "
            "real network calls. Work only inside the current directory. Install extra composer packages only "
            "if genuinely needed. You are done when `php artisan test` passes.")


def build(job, model):
    key, arm = job
    task = TASKS[key]
    out = WORK / 'runs'
    dest, log, done = out / f'{key}-{arm}', out / '_logs' / f'{key}-{arm}.jsonl', out / '_logs' / f'{key}-{arm}.done'
    if done.exists():
        return key, arm, 'cached'
    if dest.exists():
        shutil.rmtree(dest)
    subprocess.run(['cp', '-R', str(WORK / 'arms' / f"{arm}-{task['base']}"), str(dest)], check=True)
    # --setting-sources project,local keeps user-level skills and settings out of every arm;
    # --strict-mcp-config keeps user MCP servers out, so only Boost arms get Boost's server.
    cmd = ['claude', '-p', prompt(task), '--model', model, '--setting-sources', 'project,local',
           '--strict-mcp-config', '--dangerously-skip-permissions', '--output-format', 'stream-json', '--verbose']
    if arm in MCP_ARMS:
        cmd += ['--mcp-config', '.mcp.json']
    started = time.time()
    with open(log, 'w') as fh:
        rc = subprocess.run(cmd, cwd=dest, stdout=fh, stderr=subprocess.STDOUT, timeout=5400).returncode
    done.write_text(json.dumps({'rc': rc, 'seconds': round(time.time() - started)}))
    return key, arm, rc


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--arms', default='plain,skill,boost,both,augment')
    parser.add_argument('--tasks', default=','.join(TASKS))
    parser.add_argument('--model', default='sonnet')
    parser.add_argument('--concurrency', type=int, default=6)
    args = parser.parse_args()

    (WORK / 'runs' / '_logs').mkdir(parents=True, exist_ok=True)
    jobs = [(k, a) for k in args.tasks.split(',') for a in args.arms.split(',')]
    with ThreadPoolExecutor(args.concurrency) as pool:
        for key, arm, rc in pool.map(lambda job: build(job, args.model), jobs):
            print(f'{key}-{arm}: {rc}', flush=True)


if __name__ == '__main__':
    main()
