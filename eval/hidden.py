"""Run each task's hidden behavioural tests against every build of that task. No LLM is involved.

usage: python3 eval/hidden.py [--tasks a,b] [--arms skill,prev] [--parallel 4]
For every $WORK/runs/<task>-<arm>[-<n>] whose task has a `hidden` file, a copy of the build gets that file in
tests/Hidden/ and runs only it. Per-test results go to $WORK/hidden-results.json; the table shows each task × arm
as tests passed / tests run, pooled over replicates. A build whose run produced no JUnit report counts every
hidden test as failed (a missing class or a fatal error is a real failure of the brief's pinned interface).
"""
import argparse
import contextlib
import json
import os
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
import uuid
import xml.etree.ElementTree as ET
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
WORK = Path(os.environ.get('WORK', REPO / 'eval' / '.work'))
TASKS = json.loads((REPO / 'eval' / 'tasks.json').read_text())
TIMEOUT = 900  # seconds per build's hidden test run
# Valkey runs: the script's cross-process lock wait + stack start + reset (up to 600 s) plus the test run (840 s inside the container).
VALKEY_TIMEOUT = 840 + 600
INFRA_EXIT = 3  # run-hidden.sh: Docker down, image build or Valkey reset failed. The test never ran.
VALKEY_RUNNER = REPO / 'eval' / 'valkey' / 'run-hidden.sh'
VALKEY_LOCK = threading.Lock()  # tasks with "hidden_runner": "valkey" share one server, so they run one at a time


class InfraError(RuntimeError):
    """The test infrastructure failed, so the run says nothing about the build. Never scored as failed tests."""


BUILD = re.compile(r'^(?P<task>.+)-(?P<arm>[a-z]+)(?:-(?P<run>\d+))?$')


def parse_build(name, tasks=TASKS):
    """Split <task>-<arm>[-<n>] into (task, arm, run), or None when the task is unknown."""
    match = BUILD.match(name)
    if not match or match['task'] not in tasks:
        return None
    return match['task'], match['arm'], int(match['run']) if match['run'] else 1


def parse_junit(xml_text):
    """{test name: passed} for every testcase in a JUnit report."""
    results = {}
    for case in ET.fromstring(xml_text).iter('testcase'):
        failed = any(child.tag in ('failure', 'error') for child in case)
        skipped = any(child.tag == 'skipped' for child in case)
        results[case.get('name')] = not (failed or skipped)
    return results


def reconcile(tests, expected):
    """Adds every declared test that a partial report left out, as failed. Pest reports
    `test_some_name` as "Some name", and data-provider cases add a " with ..." suffix."""
    reported = [name.lower().replace('_', ' ') for name in tests]
    for method in expected:
        plain = method.removeprefix('test_').replace('_', ' ')
        if not any(r in (plain, f'test {plain}') or r.startswith((f'{plain} with ', f'test {plain} with ')) for r in reported):
            tests[method] = False
    return tests


def expected_tests(hidden_file):
    """Test method names declared in a hidden PHPUnit class (data-provider cases count once each here)."""
    return re.findall(r'public function (test_\w+)\(', Path(hidden_file).read_text())


def summarise(results):
    """{(task, arm): [passed, total, builds]} pooled over replicates."""
    table = defaultdict(lambda: [0, 0, 0])
    for row in results.values():
        cell = table[(row['task'], row['arm'])]
        cell[0] += sum(row['tests'].values())
        cell[1] += len(row['tests'])
        cell[2] += 1
    return table


def run_command(command, cwd, timeout, container=None, env=None):
    """Runs command in its own process group; on timeout kills the whole group and removes the named container.
    Returns (exit code or None on timeout, output)."""
    proc = subprocess.Popen(command, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, start_new_session=True, env=env)
    try:
        out, err = proc.communicate(timeout=timeout)
        return proc.returncode, (out or '') + (err or '')
    except subprocess.TimeoutExpired:
        with contextlib.suppress(ProcessLookupError, PermissionError):
            os.killpg(proc.pid, signal.SIGKILL)
        if container:
            subprocess.run(['docker', 'rm', '-f', container], capture_output=True)
        try:
            out, err = proc.communicate(timeout=10)
        except subprocess.TimeoutExpired:
            out, err = '', ''
        return None, f'timed out after {timeout}s\n{out or ""}{err or ""}'
    finally:
        if container:
            subprocess.run(['docker', 'rm', '-f', container], capture_output=True)


def run_hidden(build_dir, task_key):
    hidden = REPO / TASKS[task_key]['hidden']
    with tempfile.TemporaryDirectory(prefix='hidden-') as tmp:
        copy = Path(tmp) / 'app'
        # APFS clone on macOS (cheap); a plain recursive copy elsewhere.
        clone = ['cp', '-cR'] if sys.platform == 'darwin' else ['cp', '-R']
        subprocess.run([*clone, str(build_dir), str(copy)], check=True)
        target = copy / 'tests' / 'Hidden'
        target.mkdir(parents=True, exist_ok=True)
        shutil.copy(hidden, target / hidden.name)
        runner = 'vendor/bin/pest' if (copy / 'vendor/bin/pest').exists() else 'vendor/bin/phpunit'
        report = Path(tmp) / 'junit.xml'
        # "hidden_runner": "valkey" runs the test in a PHP container next to a real Valkey (see eval/valkey/README.md).
        valkey = TASKS[task_key].get('hidden_runner') == 'valkey'
        container = f'valkey-hidden-{uuid.uuid4().hex[:12]}' if valkey else None
        command, cwd = ([runner, f'tests/Hidden/{hidden.name}', '--log-junit', str(report)], copy) if not valkey else (
            [str(VALKEY_RUNNER), str(copy), hidden.name, str(report)], None)
        env = {**os.environ, 'VALKEY_CONTAINER_NAME': container} if valkey else None
        # In-process lock so the timeout below starts when this run's turn comes; run-hidden.sh serialises across processes.
        with VALKEY_LOCK if valkey else contextlib.nullcontext():
            exit_code, output = run_command(command, cwd, VALKEY_TIMEOUT if valkey else TIMEOUT, container, env)
        if valkey and exit_code == INFRA_EXIT:
            raise InfraError(f'{build_dir.name}: the Valkey stack failed, the test did not run:\n{output[-2000:]}')
        tests = {}
        # A run that timed out may have left a partial report, which could still count some passes.
        if exit_code is not None and report.exists() and report.stat().st_size:
            try:
                tests = parse_junit(report.read_text())
            except ET.ParseError:
                tests = {}
        tests = reconcile(tests, expected_tests(hidden))
        return {'tests': tests, 'exit_code': exit_code,
                'output_tail': output[-2000:] if not all(tests.values()) else ''}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--tasks', default=None)
    parser.add_argument('--arms', default=None)
    parser.add_argument('--parallel', type=int, default=4)
    args = parser.parse_args()
    tasks = set(args.tasks.split(',')) if args.tasks else None
    arms = set(args.arms.split(',')) if args.arms else None

    jobs = []
    for build_dir in sorted(p for p in (WORK / 'runs').iterdir() if p.is_dir() and not p.name.startswith('_')):
        parsed = parse_build(build_dir.name)
        if not parsed or not TASKS[parsed[0]].get('hidden'):
            continue
        task, arm, run = parsed
        if (tasks and task not in tasks) or (arms and arm not in arms):
            continue
        jobs.append((build_dir, task, arm, run))

    out = WORK / 'hidden-results.json'
    results = json.loads(out.read_text()) if out.exists() else {}

    stop = threading.Event()

    def work(job):
        build_dir, task, arm, run = job
        if stop.is_set():
            return build_dir.name, None
        try:
            return build_dir.name, {'task': task, 'arm': arm, 'run': run, **run_hidden(build_dir, task)}
        except InfraError as e:
            stop.set()
            return build_dir.name, e

    failure = None
    with ThreadPoolExecutor(args.parallel) as pool:
        for name, row in pool.map(work, jobs):
            if isinstance(row, InfraError):
                failure = failure or row
            elif row is not None:
                results[name] = row
                print(f"{name}: {sum(row['tests'].values())}/{len(row['tests'])}", flush=True)
    # Rows from runs that completed are kept; the failed and skipped builds keep their earlier rows untouched.
    out.write_text(json.dumps(results, indent=1))
    if failure:
        sys.exit(f'hidden.py: stopped on an infrastructure failure, not scored: {failure}')
    print_table(results)


def print_table(results):
    table = summarise(results)
    arms = sorted({arm for _, arm in table})
    print('\n| Task | ' + ' | '.join(arms) + ' |\n|---|' + '---|' * len(arms))
    for task in sorted({task for task, _ in table}):
        cells = []
        for arm in arms:
            passed, total, builds = table.get((task, arm), (0, 0, 0))
            cells.append(f'{passed}/{total} ({builds} builds)' if builds else '–')
        print(f'| {task} | ' + ' | '.join(cells) + ' |')


if __name__ == '__main__':
    main()
