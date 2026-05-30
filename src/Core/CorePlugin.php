<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core;

use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Core\ErrorContributors\MiddlewareErrorContributor;
use Radiergummi\OpenApi\Core\ErrorContributors\ThrowsErrorContributor;
use Radiergummi\OpenApi\Core\ErrorContributors\ValidationErrorContributor;
use Radiergummi\OpenApi\Core\Lint\RequestBodySchemaDegraded;
use Radiergummi\OpenApi\Core\Lint\RuleInvalidEnumValue;
use Radiergummi\OpenApi\Core\Lint\RuleUnknown;
use Radiergummi\OpenApi\Core\Lint\ThrowsUnmapped;
use Radiergummi\OpenApi\Core\Resolvers\CoreQueryParameterResolver;
use Radiergummi\OpenApi\Core\Resolvers\FormRequestRequestSchemaResolver;
use Radiergummi\OpenApi\Core\Resolvers\PaginatorResponseResolver;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

/**
 * Registers the framework-agnostic built-ins (the core request-schema resolver and every core lint
 * rule) into the registry. Runs first, before plugins and config extras.
 */
final class CorePlugin implements Plugin
{
    /**
     * Core Linter rules
     *
     * Rules are listed here for registration only. The authoritative severity of each rule is its
     * own `level()` method and is visible via `php artisan openapi:lint --list`.
     *
     * @var list<class-string<Rule>>
     */
    public const array RULES = [
        RequestBodySchemaDegraded::class,
        ThrowsUnmapped::class,
        RuleUnknown::class,
        RuleInvalidEnumValue::class,
    ];

    public function register(OpenApiRegistry $registry): void
    {
        $registry->addRequestSchemaResolver(FormRequestRequestSchemaResolver::class);
        $registry->addQueryParameterResolver(CoreQueryParameterResolver::class);
        $registry->addPrimaryResponseResolver(PaginatorResponseResolver::class);

        // Error-response inference contributors — registration order is load-bearing:
        // Throws first (most specific), Middleware second, Validation last (most implicit).
        // The stage that drives these contributors is registered by BaselineRegistration so
        // plugins that only contribute contributors can work without depending on Core.
        $registry->addErrorResponseContributor(ThrowsErrorContributor::class);
        $registry->addErrorResponseContributor(MiddlewareErrorContributor::class);
        $registry->addErrorResponseContributor(ValidationErrorContributor::class);

        // Register FormRequest so SuppressionCollector descends into its #[IgnoreLint] attributes
        // via the param-walk path (fromDataParameter checks against registered payload classes).
        $registry->addPayloadClass(FormRequest::class);

        foreach (self::RULES as $rule) {
            $registry->addRule($rule);
        }
    }
}
