<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fortify;

use Laravel\Fortify\Fortify;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\Fortify\Resolvers\FortifyRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\Fortify\Resolvers\FortifyResponseResolver;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

use function class_exists;

/**
 * Teaches the OpenAPI core to document Laravel Fortify's headless core-auth endpoints from a
 * hand-maintained stock-contract table. No-ops when Fortify is not installed.
 */
final class FortifyPlugin implements Plugin
{
    #[Override]
    public function register(OpenApiRegistry $registry): void
    {
        if (!class_exists(Fortify::class)) {
            return;
        }

        $registry->addRequestSchemaResolver(FortifyRequestSchemaResolver::class);
        $registry->addPrimaryResponseResolver(FortifyResponseResolver::class);
    }
}
