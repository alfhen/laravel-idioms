"""Self-check for audit.py's finding matching, rewrite application and verdicts. No PHP needed.

usage: python3 eval/test_audit.py
"""
import contextlib
import importlib.util
import io
import json
import os
import tempfile
import unittest
from pathlib import Path, PureWindowsPath

os.environ.setdefault('WORK', tempfile.mkdtemp())
spec = importlib.util.spec_from_file_location('audit', Path(__file__).with_name('audit.py'))
audit = importlib.util.module_from_spec(spec)
spec.loader.exec_module(audit)

OFFER = 'app/Models/Offer.php'
MODEL = """<?php

namespace App\\Models;

class Offer extends Model
{
    public function scopeAccepted(Builder $query): void
    {
        $query->where('accepted', true);
    }
}
"""


class Mentions(unittest.TestCase):
    run_dir = Path('/tmp/audit-test/runs/head-1')

    def check(self, finding, expected):
        self.assertIs(audit.mentions(finding, OFFER, self.run_dir), expected, finding)

    def test_line_suffixes_and_annotations(self):
        for place in [OFFER, f'{OFFER}:12', f'{OFFER}:12-15', f'{OFFER}:12:5', f'{OFFER}:12 (scopeAccepted)',
                      f'{OFFER}:12,40', f'{OFFER}#L12', f'{OFFER} (scopeAccepted)', f'././{OFFER}', f'./{OFFER}:3',
                      'app\\Models\\Offer.php:12']:
            self.check({'file': place}, True)

    def test_absolute_paths_resolve_against_the_run(self):
        self.check({'file': str(self.run_dir / OFFER) + ':12'}, True)
        self.check({'file': f'/tmp/scratch/app/{OFFER}'}, False)

    def test_windows_absolute_paths_keep_their_drive(self):
        windows_run = PureWindowsPath('C:/repo/runs/head-1')
        finding = {'file': 'C:\\repo\\runs\\head-1\\app\\Models\\Offer.php:12'}
        self.assertTrue(audit.mentions(finding, OFFER, windows_run))
        self.assertEqual(audit.place_path(finding['file'], windows_run), OFFER)
        self.assertFalse(audit.mentions({'file': 'D:\\elsewhere\\app\\Models\\Offer.php:12'}, OFFER, windows_run))
        self.assertIsNone(audit.place_path(finding['file'], self.run_dir))

    def test_no_suffix_or_prefix_matching(self):
        for place in ['Models/Offer.php', 'Offer.php:12', f'{OFFER}.bak', f'vendor/{OFFER}']:
            self.check({'file': place}, False)

    def test_locations(self):
        self.check({'file': 'app/Other.php', 'locations': [f'{OFFER}:40']}, True)
        self.check({'file': None, 'locations': [{'file': OFFER, 'line': 3}]}, True)
        self.check({'file': None, 'locations': [{'path': OFFER, 'line': 3}]}, True)
        self.check({'file': None, 'locations': [{'path': 'app\\Models\\Offer.php'}]}, True)
        self.check({'file': None, 'locations': None}, False)
        self.check({}, False)


class Apply(unittest.TestCase):
    def setUp(self):
        self.app = Path(tempfile.mkdtemp())
        (self.app / 'app' / 'Models').mkdir(parents=True)
        (self.app / OFFER).write_text(MODEL)

    def content(self):
        return (self.app / OFFER).read_text()

    def test_refuses_incomplete_suggestions_without_touching_the_file(self):
        current = '    public function scopeAccepted(Builder $query): void'
        imports_only = ['use Illuminate\\Database\\Eloquent\\Attributes\\Scope;', 'use DateTime as Clock;',
                        'use App\\{Foo, Bar};', 'use function App\\helper;', 'use const App\\LIMIT;',
                        'use function strlen;\nuse A\\B, C\\D as E;']
        comments_only = ['    // removed', '    /* gone\n       entirely */', '    # removed', '    /** @deprecated */\n    // gone']
        for finding in [{'current': current, 'suggested': ''}, {'current': current, 'suggested': '   \n'},
                        *({'current': current, 'suggested': s} for s in imports_only + comments_only),
                        {'current': current, 'suggested': 'use App\\Foo;\n// only a comment'},
                        {'current': '', 'suggested': '#[Scope]'}, {'suggested': '#[Scope]'}, {'current': current}]:
            self.assertFalse(audit.apply(self.app, OFFER, finding), finding)
            self.assertEqual(self.content(), MODEL)

    def test_replaces_current_and_adds_imports(self):
        finding = {'current': '    public function scopeAccepted(Builder $query): void',
                   'suggested': 'use Illuminate\\Database\\Eloquent\\Attributes\\Scope;\n    #[Scope]\n    protected function accepted(Builder $query): void'}
        self.assertTrue(audit.apply(self.app, OFFER, finding))
        text = self.content()
        self.assertIn('namespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Attributes\\Scope;', text)
        self.assertIn('    #[Scope]\n    protected function accepted(Builder $query): void', text)
        self.assertNotIn('scopeAccepted', text)

    def test_matches_current_ignoring_indentation(self):
        finding = {'current': "public function scopeAccepted(Builder $query): void\n{\n$query->where('accepted', true);\n}",
                   'suggested': '    public function scopeAccepted(Builder $query): void {}'}
        self.assertTrue(audit.apply(self.app, OFFER, finding))
        self.assertIn('    public function scopeAccepted(Builder $query): void {}', self.content())

    def test_trait_uses_and_attributes_count_as_code(self):
        for suggested in ['    use HasFactory;', '    use HasTags { tags as baseTags; }', '    #[Scope]']:
            self.setUp()
            self.assertTrue(audit.apply(self.app, OFFER, {'current': '    public function scopeAccepted(Builder $query): void',
                                                          'suggested': suggested}), suggested)

    def class_body(self):
        text = self.content()
        return text[text.index('class Offer'):]

    def test_qualified_trait_use_alone_stays_in_the_class(self):
        finding = {'current': '    public function scopeAccepted(Builder $query): void', 'suggested': '    use App\\Traits\\Offers;'}
        self.assertTrue(audit.complete(finding))
        self.assertTrue(audit.apply(self.app, OFFER, finding))
        self.assertIn('    use App\\Traits\\Offers;', self.class_body())
        self.assertNotIn('namespace App\\Models;\n\nuse App\\Traits\\Offers;', self.content())

    def test_trait_use_plus_method_stays_in_the_class(self):
        for trait in ['    use App\\Traits\\Offers;', '    use App\\Concerns\\HasTags { tags as baseTags; }', '    use HasFactory;']:
            self.setUp()
            suggested = f'{trait}\n\n    public function accepted(): bool\n    {{\n        return true;\n    }}'
            self.assertTrue(audit.apply(self.app, OFFER, {'current': '    public function scopeAccepted(Builder $query): void',
                                                          'suggested': suggested}), trait)
            self.assertIn(trait, self.class_body())

    def test_use_after_code_is_a_trait_use_even_unindented(self):
        imports, body = audit.split_imports('class Offer extends Model\n{\nuse App\\Traits\\Offers;\n}')
        self.assertEqual(imports, [])
        self.assertIn('use App\\Traits\\Offers;', body)

    def test_file_level_import_is_split_from_a_class_member(self):
        suggested = ('use Illuminate\\Database\\Eloquent\\Attributes\\Scope;\nuse DateTime as Clock;\n\n'
                     '    #[Scope]\n    protected function accepted(Builder $query): void')
        imports, body = audit.split_imports(suggested)
        self.assertEqual(imports, ['use Illuminate\\Database\\Eloquent\\Attributes\\Scope;', 'use DateTime as Clock;'])
        self.assertNotIn('use ', body)
        self.assertTrue(audit.apply(self.app, OFFER, {'current': '    public function scopeAccepted(Builder $query): void',
                                                      'suggested': suggested}))
        header = self.content().split('class Offer')[0]
        self.assertIn('\nuse DateTime as Clock;\n', header)
        self.assertIn('\nuse Illuminate\\Database\\Eloquent\\Attributes\\Scope;\n', header)
        self.assertNotIn('use ', self.class_body())

    def test_current_not_found(self):
        self.assertFalse(audit.apply(self.app, OFFER, {'current': 'public function nope()', 'suggested': 'x'}))
        self.assertEqual(self.content(), MODEL)


class Score(unittest.TestCase):
    """Runs score() over a fake audit tree, with PHPStan and the tests stubbed by markers in the rewritten file."""

    def setUp(self):
        self.root = Path(tempfile.mkdtemp())
        self.fixture = self.root / 'fixture'
        for spec in audit.TRAPS.values():
            path = self.fixture / spec['file']
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(MODEL)
        self.patches = {'AUDIT': self.root, 'FIXTURE': self.fixture,
                        'phpstan': lambda app: {'errors': None if 'NO_PHPSTAN' in (app / OFFER).read_text()
                                                else int('BREAK_TYPES' in (app / OFFER).read_text()), 'messages': []},
                        'tests': lambda app: {'pass': 'BREAK_TESTS' not in (app / OFFER).read_text(), 'summary': ''}}
        self.saved = {k: getattr(audit, k) for k in self.patches}
        for k, v in self.patches.items():
            setattr(audit, k, v)

    def tearDown(self):
        for k, v in self.saved.items():
            setattr(audit, k, v)

    def verdict(self, findings, trap='d: scopeAccepted() vs attribute reads'):
        runs = self.root / 'runs'
        (runs / '_logs').mkdir(parents=True, exist_ok=True)
        (runs / '_logs' / 'r-1.done').write_text('{}')
        (runs / 'r-1').mkdir(exist_ok=True)
        (runs / 'r-1' / 'audit.json').write_text(json.dumps({'findings': findings}))
        with contextlib.redirect_stdout(io.StringIO()):
            audit.score(None)
        return json.loads((self.root / 'scores.json').read_text())[0]['traps'][trap]['verdict']

    current = '    public function scopeAccepted(Builder $query): void'
    clean = '    public function scopeAccepted(Builder $query): void {}'

    def finding(self, fid, suggested, current=None):
        return {'id': fid, 'file': f'{OFFER}:7', 'current': self.current if current is None else current,
                'suggested': suggested}

    def test_left_alone(self):
        self.assertEqual(self.verdict([]), 'pass (left alone)')

    def test_verified_pass(self):
        self.assertEqual(self.verdict([self.finding('F-1', self.clean)]), 'pass (flagged safely, verified)')

    def test_missing_phpstan_result_is_not_verified(self):
        self.assertEqual(self.verdict([self.finding('F-1', "    $marker = 'NO_PHPSTAN';")]),
                         'pass (flagged safely, not verified: PHPStan result unavailable)')
        self.assertEqual(self.verdict([self.finding('F-1', "    $marker = 'NO_PHPSTAN BREAK_TESTS';")]),
                         'FAIL (rewrite breaks tests)')

    def test_flagged_without_a_complete_suggestion_is_not_verified(self):
        for suggested in ['', 'use Illuminate\\Database\\Eloquent\\Attributes\\Scope;', 'use DateTime as Clock;',
                          'use App\\{Foo, Bar};', '    // removed']:
            self.assertEqual(self.verdict([self.finding('F-1', suggested)]),
                             'pass (flagged safely, not verified: no complete suggestion in F-1)')
        self.assertEqual(self.verdict([self.finding('F-1', self.clean), self.finding('F-2', '')]),
                         'pass (flagged safely, not verified: no complete suggestion in F-2)')

    def test_unapplicable_rewrite_is_unverified_even_next_to_a_clean_one(self):
        self.assertEqual(self.verdict([self.finding('F-1', 'x', current='public function nope()')]),
                         'UNVERIFIED (rewrite not applicable)')
        self.assertEqual(self.verdict([self.finding('F-1', 'x', current='public function nope()'),
                                       self.finding('F-2', self.clean)]), 'UNVERIFIED (rewrite not applicable)')

    def test_rewrite_that_breaks_types_or_tests_fails(self):
        self.assertEqual(self.verdict([self.finding('F-1', "    $marker = 'BREAK_TYPES';")]), 'FAIL (rewrite breaks PHPStan)')
        self.assertEqual(self.verdict([self.finding('F-1', "    $marker = 'BREAK_TYPES BREAK_TESTS';")]),
                         'FAIL (rewrite breaks PHPStan and tests)')

    def test_bad_suggestion_fails_and_is_still_applied_for_evidence(self):
        finding = self.finding('F-1', '    #[Scope]\n    protected function accepted(Builder $query): void')
        self.assertEqual(self.verdict([finding]), 'FAIL')
        applied = json.loads((self.root / 'scores.json').read_text())[0]['traps']['d: scopeAccepted() vs attribute reads']['applied']
        self.assertTrue(applied[0]['applied'])

    def test_null_fields_and_odd_places(self):
        self.assertEqual(self.verdict([{'id': 'F-1', 'file': None, 'locations': None, 'current': None, 'suggested': None,
                                        'why': None, 'title': None}]), 'pass (left alone)')
        self.assertEqual(self.verdict([{**self.finding('F-1', ''), 'file': f'{OFFER}:12 (scopeAccepted)'}]),
                         'pass (flagged safely, not verified: no complete suggestion in F-1)')


if __name__ == '__main__':
    unittest.main()
