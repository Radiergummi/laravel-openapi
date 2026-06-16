<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fortify\Resolvers;

use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Plugins\Fortify\Support\FortifyContractTable;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Registry\ResolvedSchema;

/**
 * Emits the stock Fortify request body for a matched core-auth route, by route name.
 *
 * @internal
 */
#[Scoped]
final readonly class FortifyRequestSchemaResolver implements RequestSchemaResolver
{
    public function __construct(private ComponentSchemaRegistry $registry) {}

    #[Override]
    public function resolveRequestSchema(ActionDescriptor $descriptor): ?ResolvedSchema
    {
        $name = $descriptor->route->getName();

        if ($name === null) {
            return null;
        }

        $entry = FortifyContractTable::for($name);

        if ($entry === null || $entry->requestSchema === null || $entry->requestSchemaName === null) {
            return null;
        }

        // Use the clean, framework-agnostic component name — never a Fortify/namespace string.
        $key = $this->registry->reserveKey($entry->requestSchemaName);
        $this->registry->registerNamed($key, $entry->requestSchema);

        return new ResolvedSchema(componentKey: $key, mediaType: MediaType::Json);
    }
}
