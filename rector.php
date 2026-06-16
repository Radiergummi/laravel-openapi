<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;
use Rector\ValueObject\PhpVersion;

/**
 * Rector runs as a standing guard, not a one-off: `rector:check` (dry-run) gates CI, so the
 * cleanups below cannot regress back in. The rule set is intentionally narrow.
 *
 * - TYPE_DECLARATION adds native parameter/return/property types wherever they can be safely
 *   inferred. It pairs with tools/find-missing-type-hints.php, which reports EVERY missing native
 *   hint (the complete scorecard) while Rector fills only the inferable subset.
 * - Two structural dead-code rules: drop unused private-method parameters and redundant casts.
 *
 * Docblock-tag removal is deliberately excluded: pruning @param/@return/@var is a human call (some
 * carry generics or shapes that are essential for PHPStan and extraction), not an automated one.
 *
 *   composer rector:check    # report proposed changes without writing (CI gate)
 *   composer rector          # apply them, then run composer test && composer lint
 */
return RectorConfig::configure()
    ->withCache(__DIR__ . '/.cache/rector')
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withPreparedSets(typeDeclarations: true)
    ->withRules([
        RemoveUnusedPrivateMethodParameterRector::class,
        RecastingRemovalRector::class,
    ])
    ->withSkip([
        // The cached resolver factory returns list<RefSchemaResolver>; a native : array cannot
        // express the list<>, so adding it would break the method's @return contract.
        ClosureReturnTypeRector::class => [__DIR__ . '/src/OpenApiServiceProvider.php'],
    ]);
