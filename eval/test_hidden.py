"""PHP-free checks for hidden.py's parsing. Run: python3 eval/test_hidden.py"""
import json
import os
import tempfile
import unittest
from pathlib import Path

import hidden

REPO = Path(__file__).resolve().parent.parent

JUNIT = """<?xml version="1.0"?>
<testsuites><testsuite name="x">
  <testcase name="test_ok" />
  <testcase name="test_bad"><failure>nope</failure></testcase>
  <testcase name="test_err"><error>boom</error></testcase>
  <testcase name="test_skip"><skipped/></testcase>
  <testsuite name="ds"><testcase name="test_ds with data set &quot;a&quot;" /></testsuite>
</testsuite></testsuites>"""


class HiddenTest(unittest.TestCase):
    def test_parse_build_splits_task_arm_and_run(self):
        tasks = {'stock-sync-dispatcher': {}, 'sales-report': {}}
        self.assertEqual(hidden.parse_build('stock-sync-dispatcher-skill-2', tasks), ('stock-sync-dispatcher', 'skill', 2))
        self.assertEqual(hidden.parse_build('sales-report-prev', tasks), ('sales-report', 'prev', 1))
        self.assertIsNone(hidden.parse_build('unknown-skill-1', tasks))

    def test_parse_junit_counts_failures_errors_and_skips_as_not_passed(self):
        self.assertEqual(hidden.parse_junit(JUNIT), {
            'test_ok': True, 'test_bad': False, 'test_err': False, 'test_skip': False, 'test_ds with data set "a"': True})

    def test_reconcile_scores_declared_tests_missing_from_a_partial_report_as_failed(self):
        expected = ['test_marks_it_paid', 'test_retries_transient_errors', 'test_sends_one_email', 'test_runs_at_0730']
        pest = {'Marks it paid': True, 'Retries transient errors with data set "502"': True,
                'Retries transient errors with data set "503"': False}
        self.assertEqual(hidden.reconcile(dict(pest), expected), {
            **pest, 'test_sends_one_email': False, 'test_runs_at_0730': False})
        phpunit = {'test_marks_it_paid': True, 'test_retries_transient_errors with data set "502"': True}
        self.assertEqual(hidden.reconcile(dict(phpunit), expected), {
            **phpunit, 'test_sends_one_email': False, 'test_runs_at_0730': False})
        self.assertEqual(hidden.reconcile({}, expected), {name: False for name in expected})

    def test_summarise_pools_replicates(self):
        rows = {'a-skill-1': {'task': 'a', 'arm': 'skill', 'tests': {'x': True, 'y': False}},
                'a-skill-2': {'task': 'a', 'arm': 'skill', 'tests': {'x': True, 'y': True}}}
        self.assertEqual(hidden.summarise(rows)[('a', 'skill')], [3, 4, 2])

    def test_a_hung_build_scores_every_hidden_test_as_failed(self):
        key = next(k for k, t in hidden.TASKS.items() if t.get('hidden'))
        with tempfile.TemporaryDirectory() as tmp:
            runner = Path(tmp) / 'app' / 'vendor' / 'bin' / 'phpunit'
            runner.parent.mkdir(parents=True)
            # Writes a passing report for every expected test, then hangs.
            passing = ''.join(f'<testcase name="{n}" />' for n in hidden.expected_tests(hidden.REPO / hidden.TASKS[key]['hidden']))
            runner.write_text('#!/bin/sh\n'
                              f"for a; do [ \"$prev\" = --log-junit ] && echo '<testsuites><testsuite>{passing}</testsuite></testsuites>' > \"$a\"; prev=$a; done\n"
                              'echo started\nsleep 5\n')
            os.chmod(runner, 0o755)
            timeout, hidden.TIMEOUT = hidden.TIMEOUT, 1
            try:
                row = hidden.run_hidden(Path(tmp) / 'app', key)
            finally:
                hidden.TIMEOUT = timeout
        self.assertIsNone(row['exit_code'])
        self.assertTrue(row['tests'])
        self.assertFalse(any(row['tests'].values()))
        self.assertIn('timed out after 1s', row['output_tail'])

    def test_an_unreadable_report_scores_every_hidden_test_as_failed(self):
        key = next(k for k, t in hidden.TASKS.items() if t.get('hidden'))
        with tempfile.TemporaryDirectory() as tmp:
            runner = Path(tmp) / 'app' / 'vendor' / 'bin' / 'phpunit'
            runner.parent.mkdir(parents=True)
            runner.write_text('#!/bin/sh\nfor a; do [ "$prev" = --log-junit ] && echo "<testsuites><testcase" > "$a"; prev=$a; done\nexit 1\n')
            os.chmod(runner, 0o755)
            row = hidden.run_hidden(Path(tmp) / 'app', key)
        self.assertEqual(row['exit_code'], 1)
        self.assertTrue(row['tests'])
        self.assertFalse(any(row['tests'].values()))

    def test_every_hidden_file_exists_and_declares_tests(self):
        tasks = json.loads((REPO / 'eval' / 'tasks.json').read_text())
        for key, task in tasks.items():
            if task.get('hidden'):
                path = REPO / task['hidden']
                self.assertTrue(path.exists(), key)
                self.assertGreater(len(hidden.expected_tests(path)), 2, key)
                self.assertIn('namespace Tests\\Hidden;', path.read_text(), key)


if __name__ == '__main__':
    unittest.main()
