<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData;

use Override;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\FieldAttributeWrongScope;
use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\MultipartFileWithoutMultipart;
use Radiergummi\OpenApi\Plugins\SpatieData\Resolvers\DataClassRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\Resolvers\DataRefSchemaResolver;
use Radiergummi\OpenApi\Plugins\SpatieData\Resolvers\DataResponseResolver;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Spatie\LaravelData\Data;

use function class_exists;

/**
 * Teaches the OpenAPI core to extract request schemas from Spatie Data classes.
 *
 * `spatie/laravel-data` is an optional runtime dependency. When the package is not
 * installed `register()` is a no-op: no resolvers, no payload classes, and no lint
 * rules are added, so the plugin can ship in the default `config/openapi.plugins`
 * list without imposing the dependency on consumers who don't use it.
 */
final class SpatieDataPlugin implements Plugin
{
    #[Override]
    public function register(OpenApiRegistry $registry): void
    {
        if (!class_exists(Data::class)) {
            return;
        }

        $registry->addRequestSchemaResolver(DataClassRequestSchemaResolver::class);
        $registry->addRefSchemaResolver(DataRefSchemaResolver::class);
        $registry->addPrimaryResponseResolver(DataResponseResolver::class);
        $registry->addPayloadClass(Data::class);
        $registry->addRule(MultipartFileWithoutMultipart::class);
        $registry->addRule(FieldAttributeWrongScope::class);
    }
}
