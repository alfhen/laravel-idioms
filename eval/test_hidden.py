"""PHP-free checks for hidden.py's parsing. Run: python3 eval/test_hidden.py"""
import json
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

    def test_summarise_pools_replicates(self):
        rows = {'a-skill-1': {'task': 'a', 'arm': 'skill', 'tests': {'x': True, 'y': False}},
                'a-skill-2': {'task': 'a', 'arm': 'skill', 'tests': {'x': True, 'y': True}}}
        self.assertEqual(hidden.summarise(rows)[('a', 'skill')], [3, 4, 2])

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
