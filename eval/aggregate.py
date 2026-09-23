"""Summarise builds and judgements per arm.

usage: python3 eval/aggregate.py [--judgements DIR|FILE] [--mapping FILE] [--builds FILE|DIR]
Defaults read $WORK; pass eval/results/<run>/ files to re-derive the published tables.
"""
import argparse
import collections
import glob
import json
import os
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
        print(f'| {task} | ' + ' | '.join(f'{mean(scores[a]):.2f}' for a in arms) + ' |')


def build_rows(source):
    source = Path(source)
    if source.is_file():
        for b in json.loads(source.read_text()):
            yield b['arm'], {'cost': b['cost_usd'], 'seconds': b['seconds'], 'idioms': 'laravel-idioms' in b['skills']}
        return
    for log in sorted(glob.glob(str(source / '*.jsonl'))):
        name = Path(log).stem
        arm = name.rsplit('-', 1)[1]
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
                    'idioms': 'laravel-idioms' in skills}


def builds(source):
    rows = collections.defaultdict(list)
    for arm, row in build_rows(source):
        rows[arm].append(row)
    print('\n| Arm | Builds | Avg cost | Avg time | laravel-idioms fired |\n|---|---|---|---|---|')
    for arm, r in rows.items():
        print(f"| {arm} | {len(r)} | ${statistics.mean(x['cost'] for x in r):.2f} | {statistics.mean(x['seconds'] for x in r):.0f}s | "
              f"{sum(x['idioms'] for x in r)}/{len(r)} |")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--judgements', default=WORK / 'judgements')
    parser.add_argument('--mapping', default=WORK / 'judge-mapping.json')
    parser.add_argument('--builds', default=WORK / 'runs' / '_logs', help='builds.json or a directory of session logs')
    args = parser.parse_args()
    judged(load_judgements(args.judgements), json.loads(Path(args.mapping).read_text()))
    if Path(args.builds).exists():
        builds(args.builds)


if __name__ == '__main__':
    main()
