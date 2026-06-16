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
use Radiergummi\OpenApi\Plugins\Core\Resolvers\FindReturnModelResponseResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\FormRequestRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\InlineJsonResponseResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\InlineValidationRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\PaginationQueryParameterResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\PaginatorResponseResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\RequestFieldRequestSchemaResolver;
use Radiergummi\OpenApi\Plugins\Core\Resolvers\ResourceConventionResolver;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

/**
 * Registers Core's request-schema resolvers, response resolvers, error contributors, and lint
 * rules into the registry. Runs first, before other plugins.
 */
final class CorePlugin implements Plugin
{
    /**
     * Rules registered by the Core plugin. Severity is defined by each rule's `level()` method.
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

        // Runs last in the chain so it only fires when no typed payload parameter was found.
        $registry->addRequestSchemaResolver(InlineValidationRequestSchemaResolver::class);
        $registry->addQueryParameterResolver(CoreQueryParameterResolver::class);

        // Runs after the core resolver so an explicit #[QueryParam('page')] wins via dedup.
        $registry->addQueryParameterResolver(PaginationQueryParameterResolver::class);
        $registry->addPrimaryResponseResolver(PaginatorResponseResolver::class);
        $registry->addPrimaryResponseResolver(EloquentModelResponseResolver::class);

        // Model-lookup scan (find/findOrFail/firstOrFail). Runs after the reflection resolver
        // (typed Model/Collection returns) and before the inline-json scan.
        $registry->addPrimaryResponseResolver(FindReturnModelResponseResolver::class);

        // Body scan runs last; skipped when the signature already carries schema information.
        $registry->addPrimaryResponseResolver(InlineJsonResponseResolver::class);
        $registry->addOperationConventionResolver(ResourceConventionResolver::class);

        // Registration order matters: most-specific first. @throws wins, then abort() (carries
        // authored messages), then literal json() error bodies, then convention-derived contributors.
        $registry->addErrorResponseContributor(ThrowsErrorContributor::class);
        $registry->addErrorResponseContributor(AbortErrorContributor::class);
        $registry->addErrorResponseContributor(InlineJsonErrorContributor::class);
        $registry->addErrorResponseContributor(MiddlewareErrorContributor::class);
        $registry->addErrorResponseContributor(ValidationErrorContributor::class);
        $registry->addErrorResponseContributor(RouteModelBindingErrorContributor::class);
        // Both binding and findOrFail source the same ModelNotFoundException config entry;
        // the 404 is byte-identical so first-contributor-wins dedup is order-safe.
        $registry->addErrorResponseContributor(FindOrFailErrorContributor::class);

        // Required so SuppressionCollector descends into FormRequest's #[IgnoreLint] attributes.
        $registry->addPayloadClass(FormRequest::class);

        foreach (self::RULES as $rule) {
            $registry->addRule($rule);
        }
    }
}
