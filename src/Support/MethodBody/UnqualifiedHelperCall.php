<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node\Name;

use function function_exists;

/**
 * Resolves whether a called function name refers to a global helper (`abort()`, `response()`, …).
 *
 * A fully-qualified name (`\response`) always does. An unqualified name usually does too, unless a
 * function of that name is declared in the call's own namespace: PHP's name resolution would bind to
 * that local function instead, so the bounded scanners must not assume the global helper.
 *
 * @internal
 */
final readonly class UnqualifiedHelperCall
{
    /**
     * Whether the name resolves to the global helper rather than a same-namespace function that
     * would shadow it. Requires the `namespacedName` attribute set by php-parser's name resolver.
     */
    public static function resolvesToGlobalHelper(Name $name): bool
    {
        if ($name->isFullyQualified()) {
            return true;
        }

        $namespacedName = $name->getAttribute('namespacedName');

        return !($namespacedName instanceof Name && function_exists($namespacedName->toString()));
    }
}
