<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use Override;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalDuplicateKey;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalFieldsUndeclared;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalIncludeTransformerMissing;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalResponseUnbound;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalTransformerClassMissing;
use Radiergummi\OpenApi\Plugins\Fractal\Resolvers\EntityTransformerResponseResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Resolvers\FractalResponseResolver;
use Radiergummi\OpenApi\Plugins\Fractal\Resolvers\TransformerRefSchemaResolver;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

/**
 * Teaches the OpenAPI core to document `league/fractal` transformer responses.
 */
final class FractalPlugin implements Plugin
{
    #[Override]
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addRefSchemaResolver(TransformerRefSchemaResolver::class);
        $registry->addPrimaryResponseResolver(FractalResponseResolver::class);
        $registry->addPrimaryResponseResolver(EntityTransformerResponseResolver::class);
        $registry->addRule(FractalResponseUnbound::class);
        $registry->addRule(FractalFieldsUndeclared::class);
        $registry->addRule(FractalIncludeTransformerMissing::class);
        $registry->addRule(FractalDuplicateKey::class);
        $registry->addRule(FractalTransformerClassMissing::class);
    }
}
