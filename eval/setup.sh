#!/usr/bin/env bash
# Builds the base apps and one copy per setup ("arm") under $WORK:
#   bases:  base (PHPUnit), base-pest, base-filament
#   arms:   plain, skill, boost, both, augment  ×  each base
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-$REPO/eval/.work}"
LARAVEL="${LARAVEL:-laravel/laravel:^13.0}"
SKILL="$REPO/resources/boost/skills/laravel-idioms"
BASES=(base base-pest base-filament)

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

if [ ! -d base-filament ]; then
    cp -R base-pest base-filament
    (
        cd base-filament
        composer require filament/filament --no-interaction -W -q
        php artisan filament:install --panels --no-interaction >/dev/null
    )
fi

install_boost() {
    composer require laravel/boost --dev -W --no-interaction -q
    php artisan boost:install --guidelines --skills --mcp --no-interaction >/dev/null 2>&1
}

cd arms
for b in "${BASES[@]}"; do
    rm -rf "plain-$b" "skill-$b" "boost-$b" "both-$b" "augment-$b"

    # The stock skeleton's CLAUDE.md/AGENTS.md tell agents to install Boost, which would turn every arm into a Boost arm.
    cp -R "../$b" "plain-$b"
    rm -f "plain-$b/CLAUDE.md" "plain-$b/AGENTS.md"

    cp -R "plain-$b" "skill-$b"
    mkdir -p "skill-$b/.claude/skills"
    cp -R "$SKILL" "skill-$b/.claude/skills/"

    cp -R "plain-$b" "boost-$b"
    (cd "boost-$b" && install_boost)

    cp -R "boost-$b" "both-$b"
    cp -R "$SKILL" "both-$b/.claude/skills/"

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

    for arm in plain skill boost both augment; do
        printf '%-24s %s\n' "$arm-$b" "$(cd "$arm-$b" && php artisan test 2>&1 | tail -1)"
    done
done
