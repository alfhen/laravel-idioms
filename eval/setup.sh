#!/usr/bin/env bash
# Builds the base apps and one copy per setup ("arm") under $WORK:
#   bases:  base (PHPUnit), base-pest, base-filament, base-queue-bugfix (base-pest + fixtures/queue-bugfix),
#           base-api (base-pest + Sanctum), base-api-phpunit (base + Sanctum), base-orders-schema (+ fixtures/orders-schema)
#   arms:   plain, skill, boost, both, augment  ×  each base
# ARMS and BASES (space-separated) limit what is built. The arm "prev" holds the skill as it was at the git ref
# $PREV_REF (default: the latest v* tag), for comparing a change against the released skill.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-$REPO/eval/.work}"
LARAVEL="${LARAVEL:-laravel/laravel:^13.0}"
SKILL="$REPO/resources/boost/skills/laravel-idioms"
read -r -a BASES <<< "${BASES:-base base-pest base-filament base-queue-bugfix base-api base-api-phpunit base-orders-schema}"
read -r -a ARMS <<< "${ARMS:-plain skill boost both augment}"

wants() { [[ " ${ARMS[*]} " == *" $1 "* ]]; }
wants_base() { [[ " ${BASES[*]} " == *" $1 "* ]]; }

mkdir -p "$WORK/arms"
cd "$WORK"

if [ ! -d base ]; then
    composer create-project "$LARAVEL" base --no-interaction --quiet
fi

if [ ! -d base-pest ]; then
    cp -R base base-pest
    (
        cd base-pest
        composer remove phpunit/phpunit --dev --no-interaction -q
        composer require pestphp/pest pestphp/pest-plugin-laravel --dev -W --no-interaction -q
        ./vendor/bin/pest --init --no-interaction >/dev/null
        printf "<?php\n\nit('returns a successful response', function () {\n    \$this->get('/')->assertOk();\n});\n" > tests/Feature/ExampleTest.php
        printf "<?php\n\ntest('true is true', function () {\n    expect(true)->toBeTrue();\n});\n" > tests/Unit/ExampleTest.php
    )
fi

if wants_base base-filament && [ ! -d base-filament ]; then
    cp -R base-pest base-filament
    (
        cd base-filament
        composer require filament/filament --no-interaction -W -q
        php artisan filament:install --panels --no-interaction >/dev/null
    )
fi

if wants_base base-queue-bugfix && [ ! -d base-queue-bugfix ]; then
    cp -R base-pest base-queue-bugfix
    rsync -a "$REPO/eval/fixtures/queue-bugfix/" base-queue-bugfix/
    (cd base-queue-bugfix && composer dump-autoload -q && php artisan test >/dev/null)
fi

# Sanctum pre-installed (install:api + HasApiTokens), so API tasks start equal in every arm.
install_api() {
    php artisan install:api --no-interaction >/dev/null
    sed -i.bak -e 's/^use Illuminate\\Notifications\\Notifiable;/&\nuse Laravel\\Sanctum\\HasApiTokens;/' \
        -e 's/use HasFactory, Notifiable;/use HasApiTokens, HasFactory, Notifiable;/' app/Models/User.php
    rm app/Models/User.php.bak
    grep -q 'use HasApiTokens, HasFactory, Notifiable;' app/Models/User.php
}

if wants_base base-api && [ ! -d base-api ]; then
    cp -R base-pest base-api
    (cd base-api && install_api && php artisan test >/dev/null)
fi

if { wants_base base-api-phpunit || wants_base base-orders-schema; } && [ ! -d base-api-phpunit ]; then
    cp -R base base-api-phpunit
    (cd base-api-phpunit && install_api && php artisan test >/dev/null)
fi

if wants_base base-orders-schema && [ ! -d base-orders-schema ]; then
    cp -R base-api-phpunit base-orders-schema
    rsync -a "$REPO/eval/fixtures/orders-schema/" base-orders-schema/
    (cd base-orders-schema && composer dump-autoload -q && php artisan test >/dev/null)
fi

install_boost() {
    composer require laravel/boost --dev -W --no-interaction -q
    php artisan boost:install --guidelines --skills --mcp --no-interaction >/dev/null 2>&1
}

if wants prev; then
    PREV_REF="${PREV_REF:-$(git -C "$REPO" describe --tags --abbrev=0 --match 'v*')}"
    rm -rf "$WORK/prev-skill"
    mkdir -p "$WORK/prev-skill"
    git -C "$REPO" archive "$PREV_REF" resources/boost/skills/laravel-idioms | tar -x -C "$WORK/prev-skill" --strip-components=3
fi

cd arms
for b in "${BASES[@]}"; do
    rm -rf "plain-$b"
    for arm in skill prev boost both augment; do
        if wants "$arm" || { [ "$arm" = boost ] && wants both; }; then rm -rf "$arm-$b"; fi
    done

    # The stock skeleton's CLAUDE.md/AGENTS.md tell agents to install Boost, which would turn every arm into a Boost arm.
    cp -R "../$b" "plain-$b"
    rm -f "plain-$b/CLAUDE.md" "plain-$b/AGENTS.md"

    if wants skill; then
        cp -R "plain-$b" "skill-$b"
        mkdir -p "skill-$b/.claude/skills"
        cp -R "$SKILL" "skill-$b/.claude/skills/"
    fi

    if wants prev; then
        cp -R "plain-$b" "prev-$b"
        mkdir -p "prev-$b/.claude/skills"
        cp -R "$WORK/prev-skill/laravel-idioms" "prev-$b/.claude/skills/"
    fi

    if wants boost || wants both; then
        cp -R "plain-$b" "boost-$b"
        (cd "boost-$b" && install_boost)
    fi

    if wants both; then
        cp -R "boost-$b" "both-$b"
        cp -R "$SKILL" "both-$b/.claude/skills/"
    fi

    if wants augment; then
        cp -R "plain-$b" "augment-$b"
        (
            cd "augment-$b"
            composer config repositories.laravel-idioms "{\"type\":\"path\",\"url\":\"$REPO\",\"options\":{\"symlink\":false}}"
            composer require alfhen/laravel-idioms:@dev --dev -W --no-interaction -q
            install_boost
            # Boost third-party packages are opt-in; non-interactive installs only include what boost.json lists.
            python3 - <<'PY'
import json
config = json.load(open('boost.json'))
config['packages'] = ['alfhen/laravel-idioms']
config['skills'] = sorted(set(config.get('skills', [])) | {'laravel-idioms'})
json.dump(config, open('boost.json', 'w'), indent=4)
PY
            php artisan boost:install --guidelines --skills --mcp --no-interaction >/dev/null 2>&1
            test -f .claude/skills/laravel-idioms/SKILL.md
            grep -q 'Activate the `laravel-idioms` skill' CLAUDE.md
        )
    fi

    for arm in "${ARMS[@]}"; do
        printf '%-24s %s\n' "$arm-$b" "$(cd "$arm-$b" && php artisan test 2>&1 | tail -1)"
    done
done
