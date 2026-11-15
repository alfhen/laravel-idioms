"""Count the round-7 pre-registered misses in every build of their task. No LLM is involved.

usage: python3 eval/misses.py [--arms prev,skill] [--json out.json]
The misses were listed from the `prev` and `plain` pilot builds (which all passed every hidden test) before
any skill text for the round was written. Each check reports 1 (missed) or 0 per build; the table shows
misses / builds per task and arm.
"""
import argparse
import json
import os
import re
from collections import defaultdict
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
WORK = Path(os.environ.get('WORK', REPO / 'eval' / '.work'))
BUILD = re.compile(r'^(?P<task>.+)-(?P<arm>[a-z]+)(?:-(?P<run>\d+))?$')


def php(build, *dirs):
    for d in dirs:
        root = build / d
        if root.is_file():
            yield root, root.read_text(errors='ignore')
        elif root.exists():
            for p in root.rglob('*.php'):
                if 'vendor' not in p.parts and 'Hidden' not in p.parts:
                    yield p, p.read_text(errors='ignore')


def has(build, pattern, *dirs):
    rx = re.compile(pattern)
    return any(rx.search(text) for _, text in php(build, *dirs))


def webhook_route_in_web(b):
    return has(b, r'webhooks/acme', 'routes/web.php')


def search_term_truthy(b):
    return has(b, r'when\(\s*\$(term|q|search|request->(validated|input|string|query)\([\'"]q[\'"]\))\s*[,)]', 'app')


def token_flashed(b):
    return not has(b, r'dontFlash', 'bootstrap', 'app')


def renew_without_lock(b):
    return not has(b, r'lockForUpdate|->increment\(|->where\([^;]*\)->update\(', 'app')


def livewire_queries_only_first_render(b):
    fn = re.compile(r'(function\s+\w+\s*\([^)]*\)[^{]*\{|(?:it|test)\s*\()')
    for _, text in php(b, 'tests'):
        chunks = fn.split(text)
        for chunk in chunks:
            if chunk and re.search(r'QueryLog|DB::listen', chunk) and re.search(r'->(set|call)\(', chunk):
                after = chunk[re.search(r'QueryLog|DB::listen', chunk).start():]
                if re.search(r'->(set|call)\(', after):
                    return False
    return True


def inertia_global_unwrap(b):
    return has(b, r'withoutWrapping\(\)', 'app')


def inertia_resource_ceremony(b):
    return sum(1 for _ in php(b, 'app/Http/Resources')) >= 2


def throttle_every_throwable(b):
    return has(b, r'throttle\(\s*(static\s+)?fn\s*\(\s*\\?Throwable[^)]*\)\s*=>\s*Limit', 'bootstrap', 'app')


MISSES = {
    'acme-webhooks': [('webhook route in the web group', webhook_route_in_web)],
    'notes-search-api': [("when($term) drops '0'", search_term_truthy)],
    'team-invitations': [('invite token not in dontFlash', token_flashed)],
    'subscription-renewals': [('renew read-modify-write without a lock or atomic update', renew_without_lock)],
    'livewire-notes': [('query-count test only on the first render', livewire_queries_only_first_render)],
    'inertia-projects': [('global JsonResource::withoutWrapping()', inertia_global_unwrap),
                         ('two or more resource classes for props (ceremony)', inertia_resource_ceremony)],
    'partner-sync': [('exception throttle on every Throwable (look at)', throttle_every_throwable)],
}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--arms', default=None)
    parser.add_argument('--json', default=None)
    args = parser.parse_args()
    arms = set(args.arms.split(',')) if args.arms else None

    rows, table = {}, defaultdict(lambda: [0, 0])
    for build in sorted((WORK / 'runs').iterdir()):
        match = BUILD.match(build.name)
        if not match or build.name.startswith('_') or match['task'] not in MISSES:
            continue
        if arms and match['arm'] not in arms:
            continue
        for name, check in MISSES[match['task']]:
            missed = bool(check(build))
            rows.setdefault(build.name, {})[name] = missed
            cell = table[(match['task'], name, match['arm'])]
            cell[0] += missed
            cell[1] += 1
    if args.json:
        Path(args.json).write_text(json.dumps(rows, indent=1))

    seen = sorted({a for _, _, a in table})
    print('| Task | Miss | ' + ' | '.join(seen) + ' |\n|---|---|' + '---|' * len(seen))
    for task, checks in MISSES.items():
        for name, _ in checks:
            cells = [f'{table[(task, name, a)][0]}/{table[(task, name, a)][1]}' if table[(task, name, a)][1] else '–' for a in seen]
            print(f'| {task} | {name} | ' + ' | '.join(cells) + ' |')


if __name__ == '__main__':
    main()
