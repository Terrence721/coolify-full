<?php

declare(strict_types=1);

/**
 * Scratch Rector config for fixing PHPStan's `missingType.parameter` errors
 * (~61 at time of writing) across the paths PHPStan actually scans (see
 * phpstan.neon.dist). Mirrors the approach used for `missingType.return`
 * earlier: a narrow, explicit rule set rather than a broad prepared set,
 * so it only adds parameter types Rector can infer with high confidence
 * (from body usage, calling context, or an existing typed property).
 *
 * Usage:
 *   vendor/bin/rector process --config=rector-fix-param-types.php --dry-run
 *   vendor/bin/rector process --config=rector-fix-param-types.php
 *
 * Always dry-run and review the diff before applying — some inferred types
 * may need a manual nudge (e.g. a union type Rector can't fully resolve).
 * After applying, re-run:
 *   vendor/bin/phpstan analyse --error-format=raw | grep missingType.parameter
 * to see what's left, and `vendor/bin/pint --dirty` to format the result.
 *
 * NOT covered by this script (needs manual, case-by-case judgment instead
 * of blind automation — see the phpstan error breakdown for counts):
 *   - missingType.generics / missingType.iterableValue (needs domain
 *     knowledge of what a Collection/array actually holds)
 *   - property.notFound / method.notFound (usually a missing relation
 *     return-type hint on an Eloquent model, fixed one at a time like the
 *     StandaloneXxx::environment() fix)
 *   - return.missing / return.type (control-flow or real type mismatches)
 *   - argument.type / argument.templateType (largely OpenAPI attribute
 *     annotations using loose arrays instead of typed Property objects —
 *     a bulk rewrite risk, not a mechanical fix)
 *   - assign.propertyType / assign.propertyReadOnly (real mismatches)
 *
 * Delete this file once you're done with it — it's scratch tooling, not
 * part of the project's permanent Rector setup (see rector.php).
 */

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\AddParamTypeDeclarationRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddParamTypeFromPropertyTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ParamTypeByMethodCallTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ParamTypeByParentCallTypeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app/Actions',
        __DIR__.'/app/Console',
        __DIR__.'/app/Exceptions',
        __DIR__.'/app/Http',
        __DIR__.'/app/Jobs',
        __DIR__.'/app/Listeners',
        __DIR__.'/app/Notifications',
        __DIR__.'/app/Policies',
        __DIR__.'/app/Providers',
    ])
    ->withRules([
        AddParamTypeDeclarationRector::class,
        AddParamTypeFromPropertyTypeRector::class,
        ParamTypeByMethodCallTypeRector::class,
        ParamTypeByParentCallTypeRector::class,
    ]);
