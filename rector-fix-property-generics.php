<?php

declare(strict_types=1);

/**
 * Scratch Rector config for fixing PHPStan's "Property Xxx with generic
 * class Illuminate\Support\Collection does not specify its types: TKey,
 * TValue" errors (identifier=missingType.generics) on plain properties
 * and method parameters (as opposed to relation methods, which
 * rector-fix-relation-generics.php already covers).
 *
 * Unlike relation methods — where the related model class is written
 * right there in the method body ($this->belongsTo(Project::class)) —
 * a property like `public Collection $applications;` has no such single
 * signal. Its real element type only comes from tracing every place it
 * gets assigned (e.g. $this->applications = $this->server->applications();)
 * back to whatever that expression returns. TypedPropertyFromAssignsRector
 * does exactly that tracing and adds the missing @var/@param generic
 * PHPDoc when it can resolve it with confidence.
 *
 * This is a WEAKER guarantee than the relation-generics fix: if the
 * assigned expression's own type isn't fully known (e.g. it comes from
 * an untyped method, a loose array, or a union PHPStan can't narrow),
 * Rector will simply leave that property alone rather than guess. Do NOT
 * expect this to clear every missingType.generics error — re-run phpstan
 * afterward and expect to fix stragglers by hand (read where the
 * property is assigned, confirm the real element type, add the PHPDoc
 *
 * yourself: /** @var Collection<int, Application> * /).
 *
 * Running rector-fix-relation-generics.php first (so relation methods
 * carry real generics) should make this rule noticeably more effective,
 * since it gives this rule better types to trace back to.
 *
 * Usage:
 *   vendor/bin/rector process --config=rector-fix-property-generics.php --dry-run
 *   vendor/bin/rector process --config=rector-fix-property-generics.php
 *
 * Always dry-run and review the diff first. After applying:
 *   vendor/bin/pint --dirty --format agent
 *   vendor/bin/phpstan analyse --error-format=raw --memory-limit=2G | grep -c missingType.generics
 *
 * Delete this file once you're done with it — it's scratch tooling, not
 * part of the project's permanent Rector setup (see rector.php).
 */

use Illuminate\Support\Collection;
use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
    ])
    ->withRules([
        TypedPropertyFromAssignsRector::class,
    ]);
