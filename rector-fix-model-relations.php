<?php

declare(strict_types=1);

/**
 * Scratch Rector config for fixing PHPStan's "Access to an undefined
 * property Illuminate\Database\Eloquent\Model::$xxx" errors
 * (identifier=property.notFound).
 *
 * Root cause: Eloquent relation methods (environment(), project(), team(),
 * destination(), etc.) are declared without a return type. Laravel's own
 * relation methods ($this->belongsTo(), ->hasMany(), ->morphTo(), etc.)
 * DO have typed return signatures, so Rector can safely copy that type
 * onto the wrapping method. Once a relation method returns e.g. BelongsTo
 * (typed to Environment), PHPStan/Larastan can resolve magic property
 * access like $model->environment->project instead of falling back to
 * the generic Illuminate\Database\Eloquent\Model type — which is what
 * causes "$project"/"$team"/etc. to look undefined.
 *
 * This is the same fix applied by hand earlier to the 8 Standalone*
 * database models' environment() method — this script generalizes it
 * across the whole codebase, not just app/Models, since the same missing
 * return-type pattern can also occur on relation-like methods elsewhere.
 *
 * Usage:
 *   vendor/bin/rector process --config=rector-fix-model-relations.php --dry-run
 *   vendor/bin/rector process --config=rector-fix-model-relations.php
 *
 * Always dry-run and review the diff first. After applying:
 *   vendor/bin/pint --dirty --format agent
 *   vendor/bin/phpstan analyse --error-format=raw --memory-limit=2G | grep property.notFound
 * to see what's left (some property.notFound errors are unrelated to
 * relations — e.g. genuinely missing model attributes — and need a
 * manual look instead).
 *
 * Delete this file once you're done with it — it's scratch tooling, not
 * part of the project's permanent Rector setup (see rector.php).
 */

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictTypedCallRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/routes',
        __DIR__.'/config',
        __DIR__.'/bootstrap',
    ])
    ->withRules([
        ReturnTypeFromStrictTypedCallRector::class,
    ]);
