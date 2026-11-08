"""Summarise builds and judgements per arm.

usage: python3 eval/aggregate.py [--judgements DIR|FILE] [--mapping FILE] [--builds FILE|DIR] [--hidden FILE]
Defaults read $WORK; pass eval/results/<run>/ files to re-derive the published tables.
"""
import argparse
import collections
import glob
import json
import os
import re
import statistics
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
WORK = Path(os.environ.get('WORK', REPO / 'eval' / '.work'))


def load_judgements(path):
    path = Path(path)
    if path.is_file():
        return json.loads(path.read_text())
    return [json.loads(Path(f).read_text()) for f in sorted(glob.glob(str(path / '*.json')))]


def judged(judgements, mapping):
    by_arm = collections.defaultdict(lambda: collections.defaultdict(list))
    per_task = collections.defaultdict(lambda: collections.defaultdict(list))
    for j in judgements:
        for impl, arm in mapping[j['key']].items():
            v = j['v'][impl]
            row = by_arm[arm]
            row['overall'].append(v['overall'])
            row['rank'].append(v['rank'])
            row['firsts'].append(v['rank'] == 1)
            row['bugs'].append(len(v['bugs']))
            for dim, score in v['scores'].items():
                row[dim].append(score)
            per_task[j['key']][arm].append(v['overall'])
    arms = sorted(by_arm, key=lambda a: -statistics.mean(by_arm[a]['overall']))
    mean = lambda xs: statistics.mean(xs)
    print('| Arm | Mean overall | Mean rank | #1 picks | Bugs/build | Correctness |\n|---|---|---|---|---|---|')
    for a in arms:
        r = by_arm[a]
        print(f"| {a} | {mean(r['overall']):.2f} | {mean(r['rank']):.2f} | {sum(r['firsts'])} | {mean(r['bugs']):.2f} | {mean(r['correctness']):.2f} |")
    print('\n| Task | ' + ' | '.join(arms) + ' |\n|---|' + '---|' * len(arms))
    for task, scores in per_task.items():
        print(f'| {task} | ' + ' | '.join(f'{mean(scores[a]):.2f}' if scores.get(a) else '–' for a in arms) + ' |')


def build_rows(source):
    source = Path(source)
    if source.is_file():
        for b in json.loads(source.read_text()):
            yield b['arm'], {'cost': b['cost_usd'], 'seconds': b['seconds'], 'idioms': 'laravel-idioms' in b['skills'],
                             'refs': b.get('skill_files_read')}
        return
    for log in sorted(glob.glob(str(source / '*.jsonl'))):
        name = Path(log).stem
        arm = re.match(r'^.+-([a-z]+)(?:-\d+)?$', name)[1]
        done = Path(log).with_suffix('.done')
        trace = json.loads(done.read_text()) if done.exists() else {}
        skills, result = collections.Counter(), {}
        for line in open(log):
            try:
                event = json.loads(line)
            except json.JSONDecodeError:
                continue
            if event.get('type') == 'assistant':
                for block in event['message'].get('content', []):
                    if block.get('type') == 'tool_use' and block['name'] == 'Skill':
                        skills[block['input'].get('skill')] += 1
            elif event.get('type') == 'result':
                result = event
        yield arm, {'cost': result.get('total_cost_usd') or 0, 'seconds': (result.get('duration_ms') or 0) / 1000,
                    'idioms': any(s and s.split(':')[-1] == 'laravel-idioms' for s in skills),
                    'refs': [f for f in trace['skill_files_read'] if '/references/' in f] if 'skill_files_read' in trace else None}


def builds(source):
    rows = collections.defaultdict(list)
    for arm, row in build_rows(source):
        rows[arm].append(row)
    traced = any(x['refs'] is not None for r in rows.values() for x in r)
    head = '| Arm | Builds | Avg cost | Avg time | laravel-idioms fired |' + (' Read a reference |' if traced else '')
    print('\n' + head + '\n|' + '---|' * head.count(' |'))
    for arm, r in rows.items():
        line = (f"| {arm} | {len(r)} | ${statistics.mean(x['cost'] for x in r):.2f} | {statistics.mean(x['seconds'] for x in r):.0f}s | "
                f"{sum(x['idioms'] for x in r)}/{len(r)} |")
        if traced:
            line += f" {sum(bool(x['refs']) for x in r)}/{len(r)} |"
        print(line)


GUEST = 'guest_without_accept'


def is_guest_test(name):
    """The shared "guest without an Accept header" check, which one bootstrap/app.php line flips in every API task."""
    return GUEST in name.lower().replace(' ', '_')


def hidden(path):
    results = json.loads(Path(path).read_text())
    cells = collections.defaultdict(lambda: [0, 0, 0])
    shared = any(is_guest_test(name) for row in results.values() for name in row['tests'])
    for row in results.values():
        keys = [(row['task'], row['arm']), ('ALL', row['arm'])]
        own = {name: ok for name, ok in row['tests'].items() if not is_guest_test(name)}
        guest = {name: ok for name, ok in row['tests'].items() if is_guest_test(name)}
        parts = [(key, row['tests']) for key in keys]
        if shared:
            parts += [((f'ALL without {GUEST}', row['arm']), own)]
            parts += [((GUEST, row['arm']), guest)] if guest else []
        for key, tests in parts:
            cells[key][0] += sum(tests.values())
            cells[key][1] += len(tests)
            cells[key][2] += 1
    arms = sorted({arm for _, arm in cells})
    print('\n| Hidden tests | ' + ' | '.join(arms) + ' |\n|---|' + '---|' * len(arms))
    summary = ['ALL', f'ALL without {GUEST}', GUEST] if shared else ['ALL']
    for task in sorted({t for t, _ in cells if t not in summary}) + summary:
        row = []
        for arm in arms:
            passed, total, n = cells.get((task, arm), (0, 0, 0))
            row.append(f'{passed / total:.0%} ({passed}/{total}, {n} builds)' if total else '–')
        print(f'| {task} | ' + ' | '.join(row) + ' |')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--judgements', default=WORK / 'judgements')
    parser.add_argument('--mapping', default=WORK / 'judge-mapping.json')
    parser.add_argument('--builds', default=WORK / 'runs' / '_logs', help='builds.json or a directory of session logs')
    parser.add_argument('--hidden', default=WORK / 'hidden-results.json', help='hidden.py results')
    args = parser.parse_args()
    judged(load_judgements(args.judgements), json.loads(Path(args.mapping).read_text()))
    if Path(args.builds).exists():
        builds(args.builds)
    if Path(args.hidden).exists():
        hidden(args.hidden)


if __name__ == '__main__':
    main()
