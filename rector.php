<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

/**
 * Rector is scoped to the type-safety effort: it runs only the TYPE_DECLARATION rule set, which
 * adds native parameter, return, and property type declarations wherever they can be safely
 * inferred (from return statements, defaults, parent signatures, strictly-typed calls).
 *
 * It complements tools/find-missing-type-hints.php: that script reports EVERY missing native hint,
 * while Rector fills in the inferable subset. Run Rector first, then re-run the script to see the
 * residue that needs a human decision.
 *
 *   vendor/bin/rector --dry-run    # report proposed changes without writing
 *   vendor/bin/rector              # apply them, then run composer test && composer lint
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withPreparedSets(typeDeclarations: true);
