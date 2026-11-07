"""Run each (task, arm) build as a headless Claude Code session inside a fresh copy of that arm's app.

usage: python3 eval/build.py [--arms plain,skill,prev,boost,both,augment] [--tasks a,b] [--runs N] [--model sonnet] [--concurrency 6]
With --runs N each (task, arm) is built N times into <task>-<arm>-<n>; without it, into <task>-<arm>.
"""
import argparse
import json
import os
import re
import shutil
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
WORK = Path(os.environ.get('WORK', REPO / 'eval' / '.work'))
TASKS = json.loads((REPO / 'eval' / 'tasks.json').read_text())
MCP_ARMS = {'boost', 'both', 'augment'}
SKILL_FILE = re.compile(r'\.claude/skills/([^/]+)/(.+\.md)$')


def prompt(task):
    tests = ('The project uses Pest. Write Pest feature tests covering the behaviour.'
             if task['pest'] else 'Write feature tests (PHPUnit, as the skeleton uses) covering the behaviour.')
    return (f"{task['text']}\n\n{tests} The test DB is SQLite in-memory. The app and its tests must not make "
            "real network calls. Work only inside the current directory. Install extra composer packages only "
            "if genuinely needed. You are done when `php artisan test` passes.")


def session_trace(log):
    """Skills invoked and skill files read during a build, from its stream-json log."""
    skills, reads = [], []
    for line in log.read_text().splitlines():
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            continue
        if event.get('type') != 'assistant':
            continue
        for block in event.get('message', {}).get('content', []):
            if block.get('type') != 'tool_use':
                continue
            if block.get('name') == 'Skill':
                skills.append(block['input'].get('skill'))
            elif block.get('name') == 'Read':
                match = SKILL_FILE.search(block['input'].get('file_path', ''))
                if match:
                    reads.append(f'{match[1]}/{match[2]}')
            elif block.get('name') == 'Bash':
                for token in block['input'].get('command', '').split():
                    match = SKILL_FILE.search(token.strip('\'"'))
                    if match:
                        reads.append(f'{match[1]}/{match[2]}')
    return {'skills': skills, 'skill_files_read': sorted(set(reads))}


def build(job, model):
    key, arm, run = job
    task = TASKS[key]
    out = WORK / 'runs'
    name = f'{key}-{arm}' if run is None else f'{key}-{arm}-{run}'
    dest, log, done = out / name, out / '_logs' / f'{name}.jsonl', out / '_logs' / f'{name}.done'
    if done.exists():
        return name, 'cached'
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
    done.write_text(json.dumps({'rc': rc, 'seconds': round(time.time() - started), **session_trace(log)}))
    return name, rc


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--arms', default='plain,skill,boost,both,augment')
    parser.add_argument('--tasks', default=','.join(TASKS))
    parser.add_argument('--runs', type=int, default=None, help='replicates per (task, arm), named <task>-<arm>-<n>')
    parser.add_argument('--model', default='sonnet')
    parser.add_argument('--concurrency', type=int, default=6)
    args = parser.parse_args()

    (WORK / 'runs' / '_logs').mkdir(parents=True, exist_ok=True)
    runs = [None] if args.runs is None else range(1, args.runs + 1)
    jobs = [(k, a, r) for r in runs for k in args.tasks.split(',') for a in args.arms.split(',')]
    with ThreadPoolExecutor(args.concurrency) as pool:
        for name, rc in pool.map(lambda job: build(job, args.model), jobs):
            print(f'{name}: {rc}', flush=True)


if __name__ == '__main__':
    main()
