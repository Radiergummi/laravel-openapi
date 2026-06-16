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
 * Registers Spatie Data resolvers and lint rules.
 *
 * `spatie/laravel-data` is an optional dependency. When absent, `register()` is a no-op,
 * so the plugin is safe to keep in the default `config/openapi.plugins` list.
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
