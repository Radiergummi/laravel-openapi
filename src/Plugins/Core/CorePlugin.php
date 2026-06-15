<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core;

use Illuminate\Foundation\Http\FormRequest;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\AbortErrorContributor;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\FindOrFailErrorContributor;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\InlineJsonErrorContributor;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\MiddlewareErrorContributor;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\RouteModelBindingErrorContributor;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\ThrowsErrorContributor;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\ValidationErrorContributor;
use Radiergummi\OpenApi\Plugins\Core\Lint\RequestBodySchemaDegraded;
use Radiergummi\OpenApi\Plugins\Core\Lint\RuleInvalidEnumValue;
use Radiergummi\OpenApi\Plugins\Core\Lint\RuleUnknown;
use Radiergummi\OpenApi\Plugins\Core\Lint\ThrowsUnmapped;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\CoreQueryParameterResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\DiscriminatedRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\EloquentModelResponseResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\FormRequestRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\InlineJsonResponseResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\InlineValidationRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\PaginationQueryParameterResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\PaginatorResponseResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\RequestFieldRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\ResourceConventionResolver;
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

    #[Override]
    public function register(OpenApiRegistry $registry): void
    {
        $registry->addRequestSchemaResolver(DiscriminatedRequestSchemaResolver::class);
        $registry->addRequestSchemaResolver(RequestFieldRequestSchemaResolver::class);
        $registry->addRequestSchemaResolver(FormRequestRequestSchemaResolver::class);

        // Tier-1 body scan runs last in the chain (first non-null wins), so it only fires on a
        // Tier-0 miss — actions whose signature carries no typed payload parameter.
        $registry->addRequestSchemaResolver(InlineValidationRequestSchemaResolver::class);
        $registry->addQueryParameterResolver(CoreQueryParameterResolver::class);

        // Runs after the core resolver so an explicit #[QueryParam('page')] keeps its emission via
        // OperationBuilder's (name, in) dedup; otherwise page/per_page/cursor simply compose.
        $registry->addQueryParameterResolver(PaginationQueryParameterResolver::class);
        $registry->addPrimaryResponseResolver(PaginatorResponseResolver::class);
        $registry->addPrimaryResponseResolver(EloquentModelResponseResolver::class);

        // Tier-1 body scan runs last among Core's response resolvers; its return-type guard
        // additionally keeps it off any action whose signature carries schema information, so
        // the Tier-0 resolvers (including later plugins') stay authoritative.
        $registry->addPrimaryResponseResolver(InlineJsonResponseResolver::class);
        $registry->addOperationConventionResolver(ResourceConventionResolver::class);

        // Error-response inference contributors; the registration order is important: Throws
        // first (most specific), then the abort() body scan (carries authored messages), then the
        // convention-derived ones (Middleware, Validation, route-model binding), which emit
        // distinct statuses an explicit @throws would otherwise win. The stage that drives these
        // contributors is registered by BaselineRegistration so plugins that only contribute
        // contributors can work without depending on Core.
        $registry->addErrorResponseContributor(ThrowsErrorContributor::class);
        $registry->addErrorResponseContributor(AbortErrorContributor::class);
        // After Abort (an authored abort() message is at least as specific), before the envelope-only
        // contributors: a literal json() error body is more specific than the configured envelope.
        $registry->addErrorResponseContributor(InlineJsonErrorContributor::class);
        $registry->addErrorResponseContributor(MiddlewareErrorContributor::class);
        $registry->addErrorResponseContributor(ValidationErrorContributor::class);
        $registry->addErrorResponseContributor(RouteModelBindingErrorContributor::class);
        // After the binding contributor: both source the same ModelNotFoundException config entry,
        // so the 404 is byte-identical and the stage's first-contributor-wins dedup is order-safe.
        $registry->addErrorResponseContributor(FindOrFailErrorContributor::class);

        // Register FormRequest so SuppressionCollector descends into its #[IgnoreLint] attributes
        // via the param-walk path (fromDataParameter checks against registered payload classes).
        $registry->addPayloadClass(FormRequest::class);

        foreach (self::RULES as $rule) {
            $registry->addRule($rule);
        }
    }
}
