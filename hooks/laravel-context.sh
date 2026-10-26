#!/bin/sh
# Boost projects get the same pointer from the guideline Boost merges into CLAUDE.md/AGENTS.md.
dir=${CLAUDE_PROJECT_DIR:-$PWD}
[ -f "$dir/artisan" ] || exit 0
grep -q '"laravel/framework"' "$dir/composer.json" 2>/dev/null || exit 0
[ -f "$dir/boost.json" ] && exit 0
cat <<'JSON'
{"hookSpecificOutput":{"hookEventName":"SessionStart","additionalContext":"This project is a Laravel application. The laravel-idioms:laravel-idioms skill holds the conventions for its code and applies to reviewing, planning, building, refactoring, debugging and testing it, including tasks that don't mention Laravel."}}
JSON
