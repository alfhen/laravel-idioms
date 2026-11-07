"""Stage the judges' tree: $JUDGE/tasks/<task>/impl-N (builds with every trace of the arm removed) and
$JUDGE/bases/<base> (the plain base apps to diff against).

usage: python3 eval/anonymise.py --arms skill,boost,both,augment [--tasks a,b] [--replicate N]
With --replicate N the judges see build <task>-<arm>-<N> of each arm (from build.py --runs).
$JUDGE defaults to a sibling of $WORK, so nothing under it points back to the arm-named builds, the session
logs or the impl -> arm mapping, which is written to $WORK/judge-mapping.json.
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
JUDGE = Path(os.environ.get('JUDGE', WORK.parent / f'{WORK.name}-judge'))
TASKS = json.loads((REPO / 'eval' / 'tasks.json').read_text())
HIDE = ['vendor', 'node_modules', 'storage/framework', '.agents', '.claude', '.cursor', '.zed', '.mcp.json',
        'AGENTS.md', 'CLAUDE.md', 'boost.json', 'composer.lock', 'bootstrap/cache/*.php', '*.sqlite', 'tests/Hidden', 'storage/logs/*.log']
PACKAGES = {'boost': ['laravel/boost'], 'both': ['laravel/boost'], 'augment': ['laravel/boost', 'alfhen/laravel-idioms']}
LEAKS = ['laravel/boost', 'laravel-idioms']
# Text that would tell a judge which arm wrote a build (a builder quoting the skill in a comment or README).
TRACES = ['laravel-idioms', 'laravel idioms', 'Before you finish']


def anonymise(src, dest, arm):
    dest.mkdir(parents=True)
    # Anchored to the build root: an unanchored `vendor` also drops resources/views/vendor (published views).
    excludes = [h if h.startswith('*') else f'/{h}' for h in HIDE]
    subprocess.run(['rsync', '-a', *sum((['--exclude', h] for h in excludes), []), f'{src}/', f'{dest}/'], check=True)
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
    # `composer remove` exits 0 for a package that isn't installed, so a renamed package would leak silently.
    leftovers = [p for p in LEAKS if p in (dest / 'composer.json').read_text() or (dest / 'vendor' / p).exists()]
    if leftovers:
        raise RuntimeError(f'{dest}: arm still visible via {leftovers}')
    traces = subprocess.run(['grep', '-rliF', '--exclude-dir=vendor', '--exclude-dir=node_modules',
                             *sum((['-e', t] for t in TRACES), []), str(dest)], capture_output=True, text=True).stdout.split()
    if traces:
        raise RuntimeError(f'{dest}: skill traces in {traces}; strip them before judging')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--arms', default='skill,boost,both,augment')
    parser.add_argument('--tasks', default=','.join(TASKS))
    parser.add_argument('--replicate', type=int, default=None, help='judge build <task>-<arm>-<N>')
    args = parser.parse_args()
    suffix = '' if args.replicate is None else f'-{args.replicate}'
    arms = args.arms.split(',')
    keys = args.tasks.split(',')

    if JUDGE.exists():
        shutil.rmtree(JUDGE)
    for base in sorted({TASKS[k]['base'] for k in keys}):
        anonymise(WORK / 'arms' / f'plain-{base}', JUDGE / 'bases' / base, 'plain')
    mapping = {}
    for i, key in enumerate(keys):
        # A task built for only some arms (e.g. no plain build) is judged over the arms it has.
        present = [a for a in arms if (WORK / 'runs' / f'{key}-{a}{suffix}').is_dir()]
        perms = list(itertools.permutations(range(len(present))))
        order = perms[(i * 7 + 3) % len(perms)]
        mapping[key] = {}
        for slot, arm_index in enumerate(order, start=1):
            arm = present[arm_index]
            anonymise(WORK / 'runs' / f'{key}-{arm}{suffix}', JUDGE / 'tasks' / key / f'impl-{slot}', arm)
            mapping[key][f'impl-{slot}'] = arm
    (WORK / 'judge-mapping.json').write_text(json.dumps(mapping, indent=1))
    (WORK / 'judge-replicate.json').write_text(json.dumps({'replicate': args.replicate}))
    print(json.dumps({k: list(v.values()) for k, v in mapping.items()}))


if __name__ == '__main__':
    main()
