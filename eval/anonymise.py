"""Copy each task's builds into judge/<task>/impl-N with every trace of the setup removed.

usage: python3 eval/anonymise.py --arms skill,boost,both,augment [--tasks a,b]
Writes the impl -> arm mapping to $WORK/judge-mapping.json, outside the directory judges can read.
"""
import argparse
import itertools
import json
import os
import shutil
import subprocess
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
WORK = Path(os.environ.get('WORK', REPO / 'eval' / '.work'))
TASKS = json.loads((REPO / 'eval' / 'tasks.json').read_text())
HIDE = ['vendor', 'node_modules', 'storage/framework', '.agents', '.claude', '.cursor', '.zed', '.mcp.json',
        'AGENTS.md', 'CLAUDE.md', 'boost.json', 'composer.lock', 'bootstrap/cache/*.php', '*.sqlite']
PACKAGES = {'boost': ['laravel/boost'], 'both': ['laravel/boost'], 'augment': ['laravel/boost', 'alfhen/laravel-idioms']}


def anonymise(src, dest, arm):
    dest.mkdir(parents=True)
    subprocess.run(['rsync', '-a', *sum((['--exclude', h] for h in HIDE), []), f'{src}/', f'{dest}/'], check=True)
    for sub in ('cache/data', 'sessions', 'views', 'testing'):
        (dest / 'storage/framework' / sub).mkdir(parents=True, exist_ok=True)
    # A symlinked vendor/ would make Composer autoload app classes from the original build directory.
    subprocess.run(['cp', '-R', str(src / 'vendor'), str(dest / 'vendor')], check=True)
    if arm in PACKAGES:
        shutil.copy(src / 'composer.lock', dest / 'composer.lock')
        subprocess.run(['composer', 'remove', *PACKAGES[arm], '--dev', '--no-interaction', '-q'], cwd=dest, check=True)
        (dest / 'composer.lock').unlink()
        composer = json.loads((dest / 'composer.json').read_text())
        composer.pop('repositories', None)
        (dest / 'composer.json').write_text(json.dumps(composer, indent=4) + '\n')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--arms', default='skill,boost,both,augment')
    parser.add_argument('--tasks', default=','.join(TASKS))
    args = parser.parse_args()
    arms, judge = args.arms.split(','), WORK / 'judge'

    if judge.exists():
        shutil.rmtree(judge)
    perms = list(itertools.permutations(range(len(arms))))
    mapping = {}
    for i, key in enumerate(args.tasks.split(',')):
        order = perms[(i * 7 + 3) % len(perms)]
        mapping[key] = {}
        for slot, arm_index in enumerate(order, start=1):
            arm = arms[arm_index]
            anonymise(WORK / 'runs' / f'{key}-{arm}', judge / key / f'impl-{slot}', arm)
            mapping[key][f'impl-{slot}'] = arm
    (WORK / 'judge-mapping.json').write_text(json.dumps(mapping, indent=1))
    print(json.dumps({k: list(v.values()) for k, v in mapping.items()}))


if __name__ == '__main__':
    main()
