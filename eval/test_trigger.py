"""Self-check for trigger.py's stream parsing, command line and prompt set. No claude or PHP needed.

usage: python3 eval/test_trigger.py
"""
import importlib.util
import json
import re
import unittest
from pathlib import Path

HERE = Path(__file__).parent
spec = importlib.util.spec_from_file_location('trigger', HERE / 'trigger.py')
trigger = importlib.util.module_from_spec(spec)
spec.loader.exec_module(trigger)


def parse(name):
    return trigger.parse_stream((HERE / 'trigger' / 'testdata' / name).read_text())


class ParseStream(unittest.TestCase):
    def test_skill_call_counts_as_fired(self):
        run = parse('fired.jsonl')
        self.assertTrue(run['fired'])
        self.assertIsNone(run['error'])
        self.assertEqual(run['skills'], ['laravel-idioms:laravel-idioms'])
        self.assertEqual(run['cost'], 0.0125)

    def test_other_skills_and_echoed_text_do_not_count(self):
        run = parse('not-fired.jsonl')
        self.assertFalse(run['fired'])
        self.assertEqual(run['skills'], ['laravel-best-practices'])

    def test_hitting_max_turns_is_a_countable_run(self):
        run = parse('not-fired.jsonl')
        self.assertIsNone(run['error'])
        self.assertEqual(run['stop'], 'error_max_turns')

    def test_api_error_and_noise_are_an_error_not_a_miss(self):
        self.assertEqual(parse('api-error.jsonl')['error'], 'api error 529')

    def test_truncated_stream_is_an_error(self):
        self.assertEqual(trigger.parse_stream('{"type":"system","subtype":"init","skills":[]}\n')['error'], 'no result event')
        self.assertEqual(trigger.parse_stream('')['error'], 'no result event')

    def test_missing_skill_is_reported_instead_of_scored_as_zero(self):
        self.assertIn('not loaded', parse('not-loaded.jsonl')['error'])

    def test_non_object_json_lines_are_ignored(self):
        run = trigger.parse_stream('[1, 2]\n3\n"x"\n' + (HERE / 'trigger' / 'testdata' / 'fired.jsonl').read_text())
        self.assertTrue(run['fired'])


class Command(unittest.TestCase):
    def test_sessions_are_read_only(self):
        cmd = trigger.build_command('hi', 'haiku', '/plugin')
        self.assertNotIn('--dangerously-skip-permissions', cmd)
        self.assertEqual(cmd[cmd.index('--allowedTools') + 1], 'Read,Grep,Glob,Skill')
        disallowed = cmd[cmd.index('--disallowedTools') + 1].split(',')
        for tool in ('Edit', 'Write', 'Bash', 'NotebookEdit', 'Agent', 'WebFetch', 'WebSearch'):
            self.assertIn(tool, disallowed)
        self.assertEqual(cmd[cmd.index('--plugin-dir') + 1], '/plugin')
        self.assertNotIn('--setting-sources', cmd)

    def test_isolated_adds_the_two_flags(self):
        cmd = trigger.build_command('hi', 'haiku', '/plugin', isolated=True)
        self.assertEqual(cmd[cmd.index('--setting-sources') + 1], 'project,local')
        self.assertIn('--strict-mcp-config', cmd)


class Summary(unittest.TestCase):
    prompts = [{'id': 'a', 'expect': 'trigger'}, {'id': 'b', 'expect': 'skip'}]

    def result(self, id, fired, error=None, cost=0.01):
        return {'id': id, 'fired': fired, 'error': error, 'cost': cost}

    def test_totals_split_by_expectation(self):
        results = [self.result('a', True), self.result('a', False), self.result('b', False), self.result('b', True)]
        rows, totals = trigger.summarise(self.prompts, results)
        self.assertEqual(totals['trigger'], {'fired': 1, 'runs': 2})
        self.assertEqual(totals['skip'], {'fired': 1, 'runs': 2})
        self.assertIn('should trigger: 1/2 fired', trigger.format_table(rows, totals))

    def test_errored_runs_leave_the_fractions(self):
        results = [self.result('a', True), self.result('a', False, error='no result event'), self.result('b', False)]
        rows, totals = trigger.summarise(self.prompts, results)
        self.assertEqual((rows[0]['fired'], rows[0]['runs'], rows[0]['errors']), (1, 1, 1))
        self.assertEqual(totals['errors'], 1)
        self.assertAlmostEqual(totals['cost'], 0.03)


class PromptSet(unittest.TestCase):
    prompts = json.loads((HERE / 'trigger' / 'prompts.json').read_text())
    fixture = HERE / 'trigger' / 'fixture'

    def test_ids_are_unique_and_fields_valid(self):
        ids = [p['id'] for p in self.prompts]
        self.assertEqual(len(ids), len(set(ids)))
        for p in self.prompts:
            self.assertIn(p['expect'], ('trigger', 'skip'))
            self.assertIn(p['fixture'], trigger.FIXTURES)
            self.assertTrue(p['prompt'].strip())

    def test_named_files_exist_in_their_fixture(self):
        for p in self.prompts:
            root = self.fixture / trigger.FIXTURES[p['fixture']]
            for path in re.findall(r'\b(?:app|src)/[\w/]+\.(?:php|ts)\b', p['prompt']):
                self.assertTrue((root / path).exists(), f"{p['id']}: {path}")

    def test_fixtures_look_like_what_the_prompts_assume(self):
        laravel = self.fixture / 'laravel-app'
        self.assertTrue((laravel / 'artisan').exists())
        self.assertIn('"laravel/framework"', (laravel / 'composer.json').read_text())
        self.assertNotIn('laravel', (self.fixture / 'php-app' / 'composer.json').read_text().lower())
        self.assertFalse((self.fixture / 'php-app' / 'artisan').exists())
        self.assertFalse(list((self.fixture / 'ts-app').rglob('*.php')))

    def test_both_kinds_are_covered(self):
        self.assertEqual({p['expect'] for p in self.prompts}, {'trigger', 'skip'})


if __name__ == '__main__':
    unittest.main()
