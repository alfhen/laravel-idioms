#!/bin/sh
root=${CLAUDE_PROJECT_DIR:-$PWD}

is_laravel() { [ -f "$1/artisan" ] && grep -q '"laravel/framework"' "$1/composer.json" 2>/dev/null; }
# Boost writes this pointer into CLAUDE.md/AGENTS.md only when the project requires the package; a second copy here would double it.
has_pointer() { grep -qs 'laravel-idioms' "$1/CLAUDE.md" "$1/AGENTS.md"; }

has_pointer "$root" && exit 0
if is_laravel "$root"; then
    where='This project is a Laravel application.'
    code='its code'
else
    apps='' count=0
    for f in "$root"/*/artisan "$root"/*/*/artisan; do
        app=${f%/artisan}
        rel=${app#"$root"/}
        case "/$rel/" in */vendor/*|*/node_modules/*) continue ;; esac
        is_laravel "$app" && ! has_pointer "$app" || continue
        rel=$(printf '%s' "$rel" | sed 's/\\/\\\\/g; s/"/\\"/g')
        apps="$apps${apps:+, }$rel/" count=$((count + 1))
    done
    [ "$count" -eq 0 ] && exit 0
    if [ "$count" -eq 1 ]; then where="This repository contains a Laravel application in $apps."; else where="This repository contains Laravel applications in $apps."; fi
    code='that code'
fi
printf '{"hookSpecificOutput":{"hookEventName":"SessionStart","additionalContext":"%s The laravel-idioms:laravel-idioms skill holds the conventions for %s and applies to reviewing, planning, building, refactoring, debugging and testing it, including tasks that don'"'"'t mention Laravel."}}\n' "$where" "$code"
