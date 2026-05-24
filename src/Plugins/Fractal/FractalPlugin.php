<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal;

use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalDuplicateKey;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalFieldsUndeclared;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalIncludeTransformerMissing;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalResponseUnbound;
use Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules\FractalTransformerClassMissing;

/**
 * Teaches the OpenAPI core to document `league/fractal` transformer responses.
 */
final class FractalPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addRefSchemaResolver(TransformerRefSchemaResolver::class);
        $registry->addPrimaryResponseResolver(FractalResponseResolver::class);
        $registry->addRule(FractalResponseUnbound::class);
        $registry->addRule(FractalFieldsUndeclared::class);
        $registry->addRule(FractalIncludeTransformerMissing::class);
        $registry->addRule(FractalDuplicateKey::class);
        $registry->addRule(FractalTransformerClassMissing::class);
    }
}
