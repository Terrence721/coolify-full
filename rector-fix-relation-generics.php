<?php

declare(strict_types=1);

/**
 * Scratch Rector config for fixing PHPStan's "Access to an undefined
 * property Illuminate\Database\Eloquent\Model::$xxx" errors
 * (identifier=property.notFound) that remain even after relation methods
 * got a bare return type (BelongsTo, HasMany, MorphTo, etc.).
 *
 * Root cause: a bare `: HasMany` (added by rector-fix-model-relations.php
 * or by hand) tells PHPStan/Larastan "this is some HasMany relation," but
 * not HasMany *of what*. Without the generic parameter, anything that
 * iterates the relation (->get()->each(...), ->map(...), collection
 * items in a foreach) falls back to the generic base
 * Illuminate\Database\Eloquent\Model type, so accessing a real column or
 * relation on the iterated item (->project, ->pivot, ->uuid, ->name...)
 * looks undefined.
 *
 * Fix: RectorLaravel's AddGenericReturnTypeToRelationsRector adds the
 * missing generic PHPDoc, e.g.:
 *   public function applications(): HasMany
 * becomes:
 *
 *   /** @return HasMany<Application, $this> * /
 *   public function applications(): HasMany
 * by reading the related model class straight out of the method body
 * (the argument to $this->hasMany(...), ->belongsTo(...), etc.), so it's
 * a mechanical, low-risk fix — it only adds a PHPDoc annotation, no
 * runtime behavior changes.
 *
 * This only helps relation methods that already have a bare return type.
 * If phpstan still reports property.notFound after this on a method with
 * NO return type at all, run rector-fix-model-relations.php first (or
 * fix that method's return type by hand), then re-run this script.
 *
 * Usage:
 *   vendor/bin/rector process --config=rector-fix-relation-generics.php --dry-run
 *   vendor/bin/rector process --config=rector-fix-relation-generics.php
 *
 * Always dry-run and review the diff first. After applying:
 *   vendor/bin/pint --dirty --format agent
 *   vendor/bin/phpstan analyse --error-format=raw --memory-limit=2G | grep -c property.notFound
 * to see how much the count dropped. What's left after this is usually
 * either a genuinely missing/renamed column (real bug — check the
 * migration) or a magic accessor that needs an explicit @property
 * annotation on the model, not something to script blindly.
 *
 * Delete this file once you're done with it — it's scratch tooling, not
 * part of the project's permanent Rector setup (see rector.php).
 */

use Rector\Config\RectorConfig;
use RectorLaravel\Rector\ClassMethod\AddGenericReturnTypeToRelationsRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
    ])
    ->withRules([
        AddGenericReturnTypeToRelationsRector::class,
    ]);
