"""Count the pre-registered misses (rounds 7 and 8) in every build of their task. No LLM is involved.

usage: python3 eval/misses.py [--arms prev,skill] [--json out.json]
The round-7 misses were listed from the `prev` and `plain` pilot builds (which all passed every hidden test) before
any skill text for the round was written. The published round-7 counts came from this script at commit 8cc3cc3;
the detectors were tightened afterwards (token named in dontFlash, renewal-scoped guards, more search forms).
The round-8 misses were listed from the round-8 pilot (prev x3, plain x1; probe tasks prev x2, plain x1) before any
skill build ran. Each check reports 1 (missed) or 0 per build; the table shows misses / builds per task and arm.
Checks named in LOOK_AT are reported in a second table and are not part of the bar.
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
    term = r'\$(term|q|search|query)\b|\$request->(q|search)\b|\$request->(validated|input|string|query|get)\(\s*[\'"](q|search)[\'"]\s*\)|\$\w+\[[\'"](q|search)[\'"]\](\s*\?\?\s*null)?'
    return has(b, r'->when\(\s*(' + term + r')\s*[,)]', 'app')


def token_flashed(b):
    return not has(b, r'dontFlash\(\s*\[[^\]]*token', 'bootstrap', 'app')


def renew_without_lock(b):
    guard = re.compile(r'lockForUpdate|->increment\(|->where\([^;]*\)->update\(|DB::raw\(\s*[\'"]\s*\w+\s*[+-]')
    renewal = [text for p, text in php(b, 'app') if re.search(r'renew', p.name + text, re.I)]
    return not any(guard.search(text) for text in renewal)


def livewire_queries_only_first_render(b):
    fn = re.compile(r'(function\s+\w+\s*\([^)]*\)[^{]*\{|\b(?:it|test)\s*\()')
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


# ---- round 8 -------------------------------------------------------------------------------------------------

COMMENT = re.compile(r"""('(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")|/\*[\s\S]*?\*/|//[^\n]*|#(?!\[)[^\n]*""")


def strip(text):
    return COMMENT.sub(lambda m: m.group(1) or '', text)


def code(build, *dirs):
    """Like php(), with PHP comments removed (Blade templates are left as they are)."""
    for p, text in php(build, *dirs):
        yield p, text if p.name.endswith('.blade.php') else strip(text)


def hasc(build, pattern, *dirs, flags=0):
    rx = re.compile(pattern, flags)
    return any(rx.search(text) for _, text in code(build, *dirs))


def read(build, rel):
    p = build / rel
    return strip(p.read_text(errors='ignore')) if p.is_file() else None


def span(text, start):
    """Balanced (...) or [...] span starting at the opener at `start`, openers included."""
    pairs = {'(': ')', '[': ']', '{': '}'}
    opener = text[start]
    close, depth = pairs[opener], 0
    for i in range(start, len(text)):
        if text[i] == opener:
            depth += 1
        elif text[i] == close:
            depth -= 1
            if depth == 0:
                return text[start:i + 1]
    return text[start:]


def method(text, name):
    m = re.search(r'function\s+' + name + r'\b[\s\S]*?\n    \}', text)
    return m.group(0) if m else None


# team-permissions

def has_permission_to_throws(b):
    return hasc(b, r'->hasPermissionTo\(', 'app', 'routes')


def database_seeder_not_rerunnable(b):
    t = read(b, 'database/seeders/DatabaseSeeder.php') or ''
    return bool(re.search(r'::factory\(', t)) and not re.search(r'environment\(|isLocal\(|isProduction\(|runningUnitTests\(', t)


def post_body_unbounded_text_column(b):
    if not hasc(b, r'->text\(\s*[\'"]body[\'"]', 'database/migrations'):
        return False
    rule = re.compile(r'[\'"]body[\'"]\s*=>\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])')
    return not any('max' in m.group(1) for _, t in code(b, 'app') for m in rule.finditer(t))


def admin_role_lacks_editor_permissions(b):
    t = read(b, 'database/seeders/RolesAndPermissionsSeeder.php') or ''
    for m in re.finditer(r'[\'"]admin[\'"]\s*=>\s*\[', t):
        arr = span(t, m.end() - 1)
        if 'update' not in arr and not re.search(r'\.\.\.|self::|static::|\$', arr):
            return True
    for m in re.finditer(r'[\'"][^\'"\n]*update[^\'"\n]*[\'"]\s*=>\s*\[', t):
        if 'admin' not in span(t, m.end() - 1):
            return True
    return False


def database_seeder_creates_known_user(b):
    t = read(b, 'database/seeders/DatabaseSeeder.php') or ''
    return bool(re.search(r'User::(factory\(|create\(|firstOrCreate\(|updateOrCreate\(|query\(\)->(create|firstOrCreate|updateOrCreate)\()', t)) \
        and not re.search(r'environment\(|isLocal\(|isProduction\(', t)


def no_morph_map_for_role_assignments(b):
    return not hasc(b, r'enforceMorphMap|morphMap\(', 'app', 'bootstrap')


def posts_index_unpaginated(b):
    body = method(read(b, 'app/Http/Controllers/PostController.php') or '', 'index')
    return body is not None and not re.search(r'paginate\(|cursorPaginate\(|simplePaginate\(|->limit\(|->take\(', body)


def add_member_not_atomic(b):
    body = method(read(b, 'app/Models/Team.php') or '', 'addMember')
    return body is not None and not re.search(r'DB::transaction|->transaction\(|beginTransaction', body)


# shipments-api

def paginated_links_drop_filters(b):
    return hasc(b, r'->(simple|cursor)?[pP]aginate\(', 'app') and not hasc(b, r'withQueryString\(|->appends\(', 'app')


def sort_allowlist_duplicated_in_form_request(b):
    rx = re.compile(r"'sort'\s*=>\s*\[[^\]]*(Rule::in|regex:)[^\]]*(code|status|created_at)")
    return any(rx.search(line) for _, t in code(b, 'app/Http/Requests') for line in t.splitlines())


def shipments_without_foreign_keys(b):
    files = [t for _, t in code(b, 'database/migrations') if re.search(r'Schema::create\(\s*[\'"]shipments[\'"]', t)]
    return any(not re.search(r'constrained\(|->foreign\(', t) for t in files)


def ownership_columns_fillable(b):
    t = read(b, 'app/Models/Shipment.php') or ''
    return any(re.search(r'warehouse_id|user_id', span(t, m.end() - 1))
               for m in re.finditer(r'(\$fillable\s*=\s*\[|Fillable\(\s*\[)', t, re.I))


# profile-patch

def include_param_unchecked(b):
    src = r'\$request->(?:query|input|get|string|str)\(\s*[\'"]include[\'"]'
    for _, t in code(b, 'app'):
        if re.search(r'\(string\)\s*' + src, t) or re.search(r'\$request->(?:string|str)\(\s*[\'"]include', t) \
                or re.search(r'explode\([^;]*' + src, t):
            return True
        for m in re.finditer(r'\$(\w+)\s*=\s*' + src, t):
            if not re.search(r'is_(?:string|array)\(\s*\$' + m.group(1) + r'\b', t):
                return True
    return False


def import_not_normalised(b):
    files = [t for _, t in code(b, 'app/Console') if 'profiles:import' in t]
    return bool(files) and not any(re.search(r'\btrim\(|Str::trim\(|->trim\(\)', t) for t in files)


def unique_email_race_unhandled(b):
    return hasc(b, r'Rule::unique\(\s*[\'"]users|unique:users', 'app') \
        and not hasc(b, r'UniqueConstraintViolationException', 'app', 'bootstrap')


def posts_include_unbounded(b):
    stmt = re.compile(r'[^;{}]*->posts(?:\(\)[^;]*->(?:get|all|cursor|lazy)\(|\b(?!\())[^;]*;'
                      r'|[^;]*(?:->load|::with|->with)\(\s*[\'"]posts[\'"]\s*\)[^;]*;')
    bound = re.compile(r'->(limit|take|paginate|simplePaginate|cursorPaginate|chunk)\(')
    return any(not bound.search(m.group(0)) for _, t in code(b, 'app') for m in stmt.finditer(t))


# payment-states

SEND = r'Mail::[^;]*->(send|sendNow|queue)\(|->notify(Now)?\(|Notification::send(Now)?\('


def receipt_sent_inside_transaction(b):
    if hasc(b, r'ShouldQueueAfterCommit|\$afterCommit\s*=\s*true|->afterCommit\(\)', 'app'):
        return False
    queue = read(b, 'config/queue.php') or ''
    if re.search(r'[\'"]after_commit[\'"]\s*=>\s*true', queue) and hasc(b, r'implements[^{]*ShouldQueue', 'app/Mail', 'app/Notifications'):
        return False
    files = [t for _, t in code(b, 'app')]
    closure_sends = any(re.search(r'(fn\s*\([^)]*\)\s*=>|function\s*\([^)]*\)[^{]*\{)\s*(' + SEND + ')', t) for t in files)
    for t in files:
        for m in re.finditer(r'DB::transaction\s*\(|->transaction\s*\(', t):
            s = span(t, m.end() - 1)
            while (n := re.search(r'(DB::)?afterCommit\s*\(', s)):
                s = s[:n.start()] + s[n.start() + len(n.group(0)) - 1 + len(span(s, n.end() - 1)):]
            if re.search(SEND, s) or (closure_sends and re.search(r'\$(?!this\b)\w+\(\s*\$', s)):
                return True
    return False


def ledger_no_unique_backstop(b):
    files = [t for _, t in code(b, 'database/migrations')]
    for t in files:
        for m in re.finditer(r'Schema::table\(\s*\'ledger_entries\'', t):
            if '->unique(' in span(t, m.start() + len('Schema::table')):
                return False
    creates = [span(t, m.start() + len('Schema::create'))
               for t in files for m in re.finditer(r'Schema::create\(\s*\'ledger_entries\'', t)]
    return not creates or any('->unique(' not in c for c in creates)


def state_mass_assignable(b):
    t = read(b, 'app/Models/Payment.php') or ''
    return bool(re.search(r"(Fillable\(\s*\[|\$fillable\s*=\s*\[)[^\]]*'state'", t)
                or re.search(r'\$guarded\s*=\s*\[\s*\]|#\[Unguarded\]', t))


def receipt_amount_assumes_two_decimals(b):
    return hasc(b, r'amount\s*/\s*100(\.0)?\b', 'resources/views', 'app')


def no_stale_copy_pay_test(b):
    return not hasc(b, r'retrieved\(|\$stale\b|lockForUpdate|DB::table\([\'"]payments[\'"]\)->where[^;]*->update\(', 'tests')


# invoice-uploads

def upload_read_unvalidated(b):
    return hasc(b, r'(\$request|request\(\)|\$this)->(file|hasFile)\(|add(Multiple|All)?MediaFromRequest\(', 'app')


def collision_not_tested(b):
    return not any(re.search(r'->call\(\s*[\'"]POST[\'"]', t) and 'UploadedFile' in t for _, t in code(b, 'tests'))


def download_url_host_unpinned(b):
    if hasc(b, r'trustHosts|forceRootUrl|URL::useOrigin', 'app', 'bootstrap'):
        return False
    for _, t in code(b, 'app'):
        for m in re.finditer(r'download_url[\'"]\s*=>\s*(route|url|URL::\w+)\(', t):
            call = span(t, m.end() - 1)
            if not re.search(r',\s*false\s*\)$|absolute:\s*false', call):
                return True
    return False


def media_no_morph_map(b):
    return hasc(b, r'InteractsWithMedia|morphTo|morphMany', 'app') \
        and not hasc(b, r'morphMap\(|enforceMorphMap\(', 'app', 'bootstrap')


def order_fully_unguarded(b):
    t = read(b, 'app/Models/Order.php') or ''
    return bool(re.search(r'\$guarded\s*=\s*\[\s*\]', t)) or hasc(b, r'Model::unguard\(', 'app')


def collection_guard_500(b):
    return hasc(b, r'acceptsMimeTypes\(', 'app') and not hasc(b, r'FileUnacceptableForCollection', 'app', 'bootstrap') \
        and not hasc(b, r'mimetypes:[^\'"]*application/pdf', 'app')


# markdown-comments

def markdown_html_input_strip(b):
    return hasc(b, r"""['"]html_input['"]\s*=>\s*['"]strip['"]""", 'app', 'config')


def markdown_images_any_host(b):
    for _, t in code(b, 'app'):
        if re.search(r'addRenderer\(\s*\\?(\w+\\)*Image::class', t):
            return False
        for m in re.finditer(r'instanceof\s+\\?(\w+\\)*Image\b', t):
            rest = t[m.end():]
            nxt = re.search(r'(public|private|protected)\s+(static\s+)?function', rest)
            if re.search(r'PHP_URL_HOST|getHost\(|allowed_?hosts|image_hosts', rest[:nxt.start()] if nxt else rest, re.I):
                return False
    return True


def public_json_in_web_group(b):
    t = read(b, 'routes/web.php') or ''
    return any('->withoutMiddleware(' not in m.group(0)
               for m in re.finditer(r"""Route::get\(\s*['"]/?articles/\{article\}/comments['"][^;]*;""", t))


# block-user

def reset_link_host_unpinned(b):
    return not hasc(b, r'forceRootUrl\(|trustHosts\(|createUrlUsing\([\s\S]{0,400}?config\(\s*[\'"]app\.url'
                       r'|sendPasswordResetNotification[\s\S]{0,600}?config\(\s*[\'"]app\.url', 'app', 'bootstrap')


def login_unthrottled(b):
    t = read(b, 'routes/web.php') or ''
    stmts = re.findall(r'Route::post\(\s*[\'"]/?login[\'"][^;]*;', t)
    if not stmts or any('throttle' in s for s in stmts):
        return False
    if re.search(r'middleware\([^)]*throttle[^)]*\)[^;]*->group', t):
        return False
    return not hasc(b, r'RateLimiter::(tooManyAttempts|attempt|hit)\(', 'app')


def forgot_password_reveals_account(b):
    for _, t in code(b, 'app'):
        for m in re.finditer(r'sendResetLink\(', t):
            rest = t[m.end():]
            nxt = re.search(r'\bfunction\s+\w+\s*\(', rest)
            if re.search(r'(withErrors|ValidationException::withMessages)\(\s*\[[^\]]*\$status', rest[:nxt.start()] if nxt else rest):
                return True
    return False


def middleware_priority_replaced(b):
    return bool(re.search(r'\$middleware->priority\(', read(b, 'bootstrap/app.php') or ''))


def reset_keeps_other_sessions_and_tokens(b):
    for _, t in sorted(code(b, 'app')):
        i = t.find('Password::reset(')
        if i >= 0:
            rest = t[i:]
            j = rest.find('\n    public function ', 1)
            return not re.search(r'tokens\(\)->delete\(|logoutOtherDevices\(|sessions|session\.table', rest if j < 0 else rest[:j])
    return False


def state_filter_not_in_user_provider(b):
    return not hasc(b, r'withQuery\(|Auth::provider\(', 'app')


# password-reset-links

def env_outside_config(b):
    return hasc(b, r'(?<![\w$>:])env\(', 'app', 'bootstrap', 'routes')


def reset_token_flashed(b):
    return not hasc(b, r'dontFlash\(\s*\[[^\]]*[\'"]token[\'"]', 'bootstrap', 'app')


def invitation_reusable(b):
    return not hasc(b, r'invitation\w*(?:::|->)(?:[^;]*->)?delete\(\)|whereNull\(\s*[\'"]accepted_at'
                       r'|\b(abort_if|abort_unless|if)\s*\([^;{]*accepted_at|->accepted_at\s*[!=]==?\s*null'
                       r'|null\s*[!=]==?\s*\$\w+->accepted_at|isAccepted\(', 'app', flags=re.I)


def no_trusted_proxies(b):
    return not hasc(b, r'trustProxies\(|TrustProxies::at\(', 'bootstrap', 'app')


def reset_mail_sent_inline(b):
    if any(re.search(r'implements[^{]*ShouldQueue', t) and re.search('reset', t, re.I) for _, t in code(b, 'app')):
        return False
    return not hasc(b, r'(?:defer|afterResponse|dispatch)\b[^;]*sendResetLink|sendResetLink[^;]*afterResponse', 'app', 'routes')


def reset_keeps_other_sessions(b):
    return not hasc(b, r'logoutOtherDevices|AuthenticateSession|auth\.session'
                       r'|DB::table\(\s*([\'"]sessions[\'"]|config\(\s*[\'"]session\.table[\'"]\s*\))\s*\)[^;]*->delete\(',
                    'app', 'bootstrap', 'routes')


# activity-feed

def activity_morph_map_missing(b):
    return not hasc(b, r'\b(enforceMorphMap|requireMorphMap|morphMap)\s*\(', 'app', 'bootstrap') \
        and not hasc(b, r'function\s+getMorphClass\s*\(', 'app/Models')


def activity_morph_map_not_enforced(b):
    return not hasc(b, r'\b(enforceMorphMap|requireMorphMap)\s*\(', 'app', 'bootstrap')


def comment_post_id_unconstrained(b):
    return hasc(b, r"""['"]post_id['"]""", 'database/migrations') and not hasc(
        b, r"""foreignId\(\s*['"]post_id['"]\s*\)[^;]*->constrained\(|foreignIdFor\(\s*\\?(App\\Models\\)?Post::class[^;]*->constrained\("""
           r"""|->foreign\(\s*['"]post_id['"]""", 'database/migrations')


def models_fully_unguarded(b):
    return hasc(b, r'\$guarded\s*=\s*\[\s*\]', 'app/Models') or hasc(b, r'Model::unguard\s*\(', 'app')


def invoice_amount_cents_32bit(b):
    return hasc(b, r"""->(unsignedInteger|integer|unsignedMediumInteger|mediumInteger|unsignedSmallInteger|smallInteger"""
                   r"""|unsignedTinyInteger|tinyInteger)\(\s*['"]amount_cents['"]""", 'database/migrations')


# team-roles-page

def paginates_every_user_list(b):
    return any(re.search(r'User::(query\(\)|with|select|orderBy)', t) and 'roles' in t
               and re.search(r'->(simplePaginate|cursorPaginate|paginate)\(', t) for _, t in code(b, 'app', 'routes'))


def has_roles_without_morph_map(b):
    return hasc(b, r'\buse\s+[^;]*\bHasRoles\b', 'app/Models') \
        and not hasc(b, r'(enforceMorphMap|morphMap|requireMorphMap)\s*\(', 'app', 'bootstrap', 'config')


def pest_global_helper_function(b):
    return any(re.search(r'^\s*(it|test|describe)\s*\(', t, re.M) and not re.search(r'^\s*(final\s+|abstract\s+)?class\s', t, re.M)
               and re.search(r'^function\s+\w+\s*\(', t, re.M) for _, t in code(b, 'tests'))


MISSES = {
    'acme-webhooks': [('webhook route in the web group', webhook_route_in_web)],
    'notes-search-api': [("when($term) drops '0'", search_term_truthy)],
    'team-invitations': [('invite token not in dontFlash', token_flashed)],
    'subscription-renewals': [('renew read-modify-write without a lock or atomic update', renew_without_lock)],
    'livewire-notes': [('query-count test only on the first render', livewire_queries_only_first_render)],
    'inertia-projects': [('global JsonResource::withoutWrapping()', inertia_global_unwrap),
                         ('two or more resource classes for props (ceremony)', inertia_resource_ceremony)],
    'partner-sync': [('exception throttle on every Throwable (look at)', throttle_every_throwable)],
    # round 8
    'team-permissions': [(f.__name__, f) for f in (
        has_permission_to_throws, database_seeder_not_rerunnable, post_body_unbounded_text_column,
        admin_role_lacks_editor_permissions, database_seeder_creates_known_user, no_morph_map_for_role_assignments,
        posts_index_unpaginated, add_member_not_atomic)],
    'shipments-api': [(f.__name__, f) for f in (
        paginated_links_drop_filters, sort_allowlist_duplicated_in_form_request, shipments_without_foreign_keys,
        ownership_columns_fillable)],
    'profile-patch': [(f.__name__, f) for f in (
        include_param_unchecked, import_not_normalised, unique_email_race_unhandled, posts_include_unbounded)],
    'payment-states': [(f.__name__, f) for f in (
        receipt_sent_inside_transaction, ledger_no_unique_backstop, state_mass_assignable,
        receipt_amount_assumes_two_decimals, no_stale_copy_pay_test)],
    'invoice-uploads': [(f.__name__, f) for f in (
        upload_read_unvalidated, collision_not_tested, download_url_host_unpinned, media_no_morph_map,
        order_fully_unguarded, collection_guard_500)],
    'markdown-comments': [(f.__name__, f) for f in (
        markdown_html_input_strip, markdown_images_any_host, public_json_in_web_group)],
    'block-user': [(f.__name__, f) for f in (
        reset_link_host_unpinned, login_unthrottled, forgot_password_reveals_account, middleware_priority_replaced,
        reset_keeps_other_sessions_and_tokens, state_filter_not_in_user_provider)],
    'password-reset-links': [(f.__name__, f) for f in (
        env_outside_config, reset_token_flashed, invitation_reusable, no_trusted_proxies, reset_mail_sent_inline,
        reset_keeps_other_sessions)],
    'activity-feed': [(f.__name__, f) for f in (
        activity_morph_map_missing, activity_morph_map_not_enforced, comment_post_id_unconstrained,
        models_fully_unguarded, invoice_amount_cents_32bit)],
    'team-roles-page': [(f.__name__, f) for f in (
        paginates_every_user_list, has_roles_without_morph_map, pest_global_helper_function)],
}

# Reported but not part of the bar (in_bar=false): design calls, latent risks, or defects a hidden test already counts.
LOOK_AT = {
    'admin_role_lacks_editor_permissions', 'database_seeder_creates_known_user', 'no_morph_map_for_role_assignments',
    'posts_index_unpaginated', 'add_member_not_atomic',
    'sort_allowlist_duplicated_in_form_request', 'shipments_without_foreign_keys', 'ownership_columns_fillable',
    'import_not_normalised', 'unique_email_race_unhandled', 'posts_include_unbounded',
    'ledger_no_unique_backstop', 'state_mass_assignable', 'receipt_amount_assumes_two_decimals', 'no_stale_copy_pay_test',
    'collision_not_tested', 'download_url_host_unpinned', 'media_no_morph_map', 'order_fully_unguarded',
    'collection_guard_500',
    'markdown_html_input_strip', 'markdown_images_any_host', 'public_json_in_web_group',
    'forgot_password_reveals_account', 'middleware_priority_replaced', 'reset_keeps_other_sessions_and_tokens',
    'state_filter_not_in_user_provider',
    'reset_token_flashed', 'invitation_reusable', 'no_trusted_proxies', 'reset_mail_sent_inline',
    'reset_keeps_other_sessions',
    'activity_morph_map_not_enforced', 'comment_post_id_unconstrained', 'models_fully_unguarded',
    'invoice_amount_cents_32bit',
    'paginates_every_user_list', 'has_roles_without_morph_map', 'pest_global_helper_function',
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
    for title, in_bar in (('In the bar', True), ('Look at (not counted)', False)):
        print(f'\n{title}\n\n| Task | Miss | ' + ' | '.join(seen) + ' |\n|---|---|' + '---|' * len(seen))
        for task, checks in MISSES.items():
            for name, _ in checks:
                if (name not in LOOK_AT) != in_bar:
                    continue
                cells = [f'{table[(task, name, a)][0]}/{table[(task, name, a)][1]}' if table[(task, name, a)][1] else '–' for a in seen]
                print(f'| {task} | {name} | ' + ' | '.join(cells) + ' |')


if __name__ == '__main__':
    main()
