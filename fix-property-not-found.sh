#!/usr/bin/env bash
#
# Fixes PHPStan's "Access to an undefined property ...::$xxx"
# (identifier=property.notFound) across the whole codebase.
#
# This error mostly comes from Eloquent relation methods that either have
# no return type at all, or have a bare return type (BelongsTo, HasMany,
# MorphTo...) without the generic parameter saying what model it relates
# to. Without that, anything iterating the relation falls back to the
# generic Illuminate\Database\Eloquent\Model class, so real columns and
# relations on the items look undefined.
#
# This script chains the tools that proved effective on this exact error
# class earlier in the project:
#   1. php artisan ide-helper:models --write
#        Introspects every model's actual relations/columns against the
#        live dev database and writes accurate @property-read docblocks
#        directly into each model file. Requires a running dev database
#        (see scripts/setup-dev-database.sh) and a booted app.
#   2. vendor/bin/rector (rector-fix-relation-generics.php)
#        Adds the missing <TRelatedModel, $this> generic to any relation
#        method that already has a bare return type but ide-helper didn't
#        touch (ide-helper writes class-level @property docblocks; this
#        adds the generic on the relation METHOD itself, which some
#        codepaths need too).
#   3. vendor/bin/rector (rector-fix-property-generics.php)
#        Traces plain (non-relation) Collection-typed properties/params
#        back to their assignment and adds generics where it can resolve
#        the element type with confidence.
#   4. vendor/bin/pint --dirty
#        Formats whatever the above changed.
#
# What this WON'T fix (needs a human, not a script):
#   - Third-party SDK objects (Stripe\StripeObject, etc.) — not Eloquent
#     models, no relation to generate a type from.
#   - ->pivot access on belongsToMany relations with extra pivot columns
#     — needs a real custom Pivot model class (see app/Models/TeamUserPivot.php
#     and app/Models/AdditionalDestinationPivot.php for the pattern), wired
#     up via ->using(YourPivot::class) on the relation. That's a judgment
#     call about naming/placement, not something to generate blindly.
#   - Genuinely missing/renamed columns (a real bug — check the migration).
#
# Usage:
#   bash fix-property-not-found.sh
#
# Requires: a running dev database (bash scripts/setup-dev-database.sh),
# Docker Desktop running, and the app's .env configured.

set -euo pipefail

cd "$(dirname "$0")"

if ! docker info > /dev/null 2>&1; then
    echo "ERROR: Docker doesn't seem to be running. Start Docker Desktop and try again." >&2
    exit 1
fi

if [ ! -f vendor/bin/phpstan ] || [ ! -f vendor/bin/rector ] || [ ! -f vendor/bin/pint ]; then
    echo "ERROR: vendor/bin tools not found. Run 'composer install' first." >&2
    exit 1
fi

if [ ! -d vendor/barryvdh/laravel-ide-helper ]; then
    echo "ERROR: barryvdh/laravel-ide-helper is not installed." >&2
    echo "        Run: composer require --dev barryvdh/laravel-ide-helper --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix" >&2
    exit 1
fi

echo "==> Clearing phpstan's result cache (avoids stale error counts)..."
vendor/bin/phpstan clear-result-cache

echo "==> [1/4] Generating model docblocks from the live database..."
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T -u www-data coolify \
    php artisan ide-helper:models --write --no-interaction \
    || php artisan ide-helper:models --write --no-interaction

echo "==> [2/4] Adding generics to relation methods (rector-fix-relation-generics.php)..."
vendor/bin/rector process --config=rector-fix-relation-generics.php --no-progress-bar

echo "==> [3/4] Adding generics to plain Collection properties/params (rector-fix-property-generics.php)..."
vendor/bin/rector process --config=rector-fix-property-generics.php --no-progress-bar

echo "==> [4/4] Formatting changed files..."
vendor/bin/pint --dirty --format agent

echo "==> Done. Remaining property.notFound count:"
vendor/bin/phpstan analyse --no-progress --error-format=raw --memory-limit=2G 2>/dev/null | grep -c "property.notFound\]" || true
