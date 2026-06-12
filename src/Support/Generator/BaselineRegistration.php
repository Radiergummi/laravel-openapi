<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Lint\Rules\ActionMissingReturnType;
use Radiergummi\OpenApi\Lint\Rules\ComponentNameNamingInconsistent;
use Radiergummi\OpenApi\Lint\Rules\ComponentOrphaned;
use Radiergummi\OpenApi\Lint\Rules\DeprecatedAttribute;
use Radiergummi\OpenApi\Lint\Rules\DeprecatedNoReplacement;
use Radiergummi\OpenApi\Lint\Rules\DeprecatedNoSunsetDate;
use Radiergummi\OpenApi\Lint\Rules\DiscriminatorInvalidMapping;
use Radiergummi\OpenApi\Lint\Rules\EnumValuesUndocumented;
use Radiergummi\OpenApi\Lint\Rules\ErrorsResolverFailed;
use Radiergummi\OpenApi\Lint\Rules\ExternaldocsInvalidUrl;
use Radiergummi\OpenApi\Lint\Rules\FieldConflictingType;
use Radiergummi\OpenApi\Lint\Rules\FieldDescriptionMissing;
use Radiergummi\OpenApi\Lint\Rules\FieldEnumMismatch;
use Radiergummi\OpenApi\Lint\Rules\FieldInvalidFormat;
use Radiergummi\OpenApi\Lint\Rules\FieldNameNamingInconsistent;
use Radiergummi\OpenApi\Lint\Rules\FieldNoEffect;
use Radiergummi\OpenApi\Lint\Rules\HeaderDescriptionMissing;
use Radiergummi\OpenApi\Lint\Rules\HeaderInvalidName;
use Radiergummi\OpenApi\Lint\Rules\HeaderNameNamingInconsistent;
use Radiergummi\OpenApi\Lint\Rules\HideExposeConflict;
use Radiergummi\OpenApi\Lint\Rules\InfoDescriptionMissing;
use Radiergummi\OpenApi\Lint\Rules\InfoMetadataIncomplete;
use Radiergummi\OpenApi\Lint\Rules\LinkBothOperationIdAndRef;
use Radiergummi\OpenApi\Lint\Rules\LinkDuplicateName;
use Radiergummi\OpenApi\Lint\Rules\LinkInvalidOperation;
use Radiergummi\OpenApi\Lint\Rules\LinkInvalidParameter;
use Radiergummi\OpenApi\Lint\Rules\LinkNeitherOperationIdNorRef;
use Radiergummi\OpenApi\Lint\Rules\LinkParameterRequiredMissing;
use Radiergummi\OpenApi\Lint\Rules\MetaNoSuppressionReason;
use Radiergummi\OpenApi\Lint\Rules\MetaTooManySuppressions;
use Radiergummi\OpenApi\Lint\Rules\MetaUnknownRule;
use Radiergummi\OpenApi\Lint\Rules\OperationDescriptionMissing;
use Radiergummi\OpenApi\Lint\Rules\OperationIdDuplicate;
use Radiergummi\OpenApi\Lint\Rules\OperationIdInvalidChars;
use Radiergummi\OpenApi\Lint\Rules\OperationIdMissing;
use Radiergummi\OpenApi\Lint\Rules\OperationIdNamingInconsistent;
use Radiergummi\OpenApi\Lint\Rules\OperationSecurityMissing;
use Radiergummi\OpenApi\Lint\Rules\OperationSummaryEqualsDescription;
use Radiergummi\OpenApi\Lint\Rules\OperationTagMissing;
use Radiergummi\OpenApi\Lint\Rules\OverridesUnknownField;
use Radiergummi\OpenApi\Lint\Rules\OverridesUnused;
use Radiergummi\OpenApi\Lint\Rules\ParameterDescriptionMissing;
use Radiergummi\OpenApi\Lint\Rules\ParameterDuplicateName;
use Radiergummi\OpenApi\Lint\Rules\ParameterExampleConflict;
use Radiergummi\OpenApi\Lint\Rules\ParameterExampleMissing;
use Radiergummi\OpenApi\Lint\Rules\ParameterNameNamingInconsistent;
use Radiergummi\OpenApi\Lint\Rules\ParameterPathMustBeRequired;
use Radiergummi\OpenApi\Lint\Rules\ParameterQueryArrayNoExplode;
use Radiergummi\OpenApi\Lint\Rules\ParameterQueryNoSchema;
use Radiergummi\OpenApi\Lint\Rules\PathParameterUndeclared;
use Radiergummi\OpenApi\Lint\Rules\PathParameterUndefined;
use Radiergummi\OpenApi\Lint\Rules\PathSegmentNamingInconsistent;
use Radiergummi\OpenApi\Lint\Rules\PathTrailingSlashInconsistent;
use Radiergummi\OpenApi\Lint\Rules\PublicEndpointContradictsMiddleware;
use Radiergummi\OpenApi\Lint\Rules\QueryParamDuplicate;
use Radiergummi\OpenApi\Lint\Rules\RefBroken;
use Radiergummi\OpenApi\Lint\Rules\RequestBodyDescriptionMissing;
use Radiergummi\OpenApi\Lint\Rules\RequestBodyExampleMissing;
use Radiergummi\OpenApi\Lint\Rules\RequestBodyNoContent;
use Radiergummi\OpenApi\Lint\Rules\RequestBodyOnGetOrDelete;
use Radiergummi\OpenApi\Lint\Rules\RequestEmpty;
use Radiergummi\OpenApi\Lint\Rules\ResponseDescriptionMissing;
use Radiergummi\OpenApi\Lint\Rules\ResponseDuplicateStatus;
use Radiergummi\OpenApi\Lint\Rules\ResponseExampleMissing;
use Radiergummi\OpenApi\Lint\Rules\ResponseNoError;
use Radiergummi\OpenApi\Lint\Rules\ResponseNoSuccess;
use Radiergummi\OpenApi\Lint\Rules\ResponseRedirectWithoutLocation;
use Radiergummi\OpenApi\Lint\Rules\ResponseRefUnresolvable;
use Radiergummi\OpenApi\Lint\Rules\ResponseStatusUnconventional;
use Radiergummi\OpenApi\Lint\Rules\ResponseSuccessEmptyBody;
use Radiergummi\OpenApi\Lint\Rules\SchemaAllOfTypeConflict;
use Radiergummi\OpenApi\Lint\Rules\SchemaConstraintsMissing;
use Radiergummi\OpenApi\Lint\Rules\SchemaDescriptionMissing;
use Radiergummi\OpenApi\Lint\Rules\SchemaEnumEmpty;
use Radiergummi\OpenApi\Lint\Rules\SchemaEnumTypeMismatch;
use Radiergummi\OpenApi\Lint\Rules\SchemaExampleMissing;
use Radiergummi\OpenApi\Lint\Rules\SchemaNullableViaDeprecatedKeyword;
use Radiergummi\OpenApi\Lint\Rules\SchemaRequiredWithoutProperty;
use Radiergummi\OpenApi\Lint\Rules\ScopeOverlyBroad;
use Radiergummi\OpenApi\Lint\Rules\SecurityInvalidScope;
use Radiergummi\OpenApi\Lint\Rules\SecuritySchemeUndefined;
use Radiergummi\OpenApi\Lint\Rules\ServerInvalidUrl;
use Radiergummi\OpenApi\Lint\Rules\ServerVariableUndeclared;
use Radiergummi\OpenApi\Lint\Rules\SpecConfigOrphaned;
use Radiergummi\OpenApi\Lint\Rules\SpecInvalid;
use Radiergummi\OpenApi\Lint\Rules\SpecRouteOrphaned;
use Radiergummi\OpenApi\Lint\Rules\SpecUnknownReference;
use Radiergummi\OpenApi\Lint\Rules\StreamingNoContentType;
use Radiergummi\OpenApi\Lint\Rules\SummaryMissing;
use Radiergummi\OpenApi\Lint\Rules\TagDuplicate;
use Radiergummi\OpenApi\Lint\Rules\TagNameNamingInconsistent;
use Radiergummi\OpenApi\Lint\Rules\TagsNoDescription;
use Radiergummi\OpenApi\Lint\Rules\TagUndeclaredAtRoot;
use Radiergummi\OpenApi\Lint\Rules\ThrowsTransitiveMissing;
use Radiergummi\OpenApi\Lint\Rules\VisibilityAttributeNoOp;
use Radiergummi\OpenApi\Lint\Rules\WebhookDescriptionMissing;
use Radiergummi\OpenApi\Lint\Rules\WebhookNameDuplicate;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Support\Generator\Stages\ComponentsStage;
use Radiergummi\OpenApi\Support\Generator\Stages\ErrorResponseInferenceStage;
use Radiergummi\OpenApi\Support\Generator\Stages\OverridesStage;
use Radiergummi\OpenApi\Support\Generator\Stages\PathsStage;
use Radiergummi\OpenApi\Support\Generator\Stages\RootStage;
use Radiergummi\OpenApi\Support\Generator\Stages\SecurityStage;
use Radiergummi\OpenApi\Support\Generator\Stages\TransformersStage;

/**
 * Assembles the {@see OpenApiRegistry}: the ordered stage pipeline, the baseline lint rules, and
 * the configured error-envelope resolver, then seals it.
 *
 * The stage order is the single top-to-bottom sequence in {@see assemble()}: pre-plugin baseline
 * stages (`RootStage` → `PathsStage` → `ErrorResponseInferenceStage`), then each plugin in the
 * given order, then post-plugin stages (`ComponentsStage` flush → `SecurityStage` → terminal
 * `OverridesStage` → `TransformersStage`). That is the one place stage order is expressed.
 *
 * This class lives in `Support` and must stay plugin-agnostic, so the plugin list and envelope
 * resolver arrive as class-strings from {@see \Radiergummi\OpenApi\OpenApiServiceProvider} (which
 * owns the Laravel/config glue): assembly iterates the {@see Plugin} contract without ever
 * referencing a concrete plugin.
 *
 * The baseline rules include those whose findings are emitted by baseline stages — e.g.
 * `errors.resolver-failed`, emitted by `ErrorResponseInferenceStage` when an
 * {@see ErrorResponseResolver} throws. Tying these rule registrations to the baseline avoids the
 * "Core was disabled and now my suppression annotation trips meta.unknown-rule" failure mode.
 *
 * @internal
 */
final class BaselineRegistration
{
    /**
     * Baseline Linter rules
     *
     * Rules are listed here for registration only. The authoritative severity of each rule is its
     * own `level()` method and is visible via `php artisan openapi:lint --list`.
     *
     * @var list<class-string<Rule>>
     */
    public const array RULES = [
        SpecInvalid::class,
        LinkInvalidOperation::class,
        LinkInvalidParameter::class,
        LinkParameterRequiredMissing::class,
        DiscriminatorInvalidMapping::class,
        SecurityInvalidScope::class,
        ResponseDuplicateStatus::class,
        QueryParamDuplicate::class,
        FieldConflictingType::class,
        FieldInvalidFormat::class,
        FieldEnumMismatch::class,

        ServerInvalidUrl::class,
        ServerVariableUndeclared::class,

        OperationIdDuplicate::class,
        RefBroken::class,
        PathParameterUndeclared::class,
        PathParameterUndefined::class,
        ParameterPathMustBeRequired::class,
        ParameterDuplicateName::class,
        LinkBothOperationIdAndRef::class,
        LinkNeitherOperationIdNorRef::class,
        LinkDuplicateName::class,
        WebhookNameDuplicate::class,
        SecuritySchemeUndefined::class,
        SchemaRequiredWithoutProperty::class,
        SchemaEnumEmpty::class,
        SchemaEnumTypeMismatch::class,
        PublicEndpointContradictsMiddleware::class,
        ResponseNoSuccess::class,
        ResponseSuccessEmptyBody::class,
        ResponseRedirectWithoutLocation::class,
        OperationIdMissing::class,
        SchemaAllOfTypeConflict::class,
        ParameterQueryNoSchema::class,
        TagDuplicate::class,
        ResponseDescriptionMissing::class,

        RequestEmpty::class,
        ErrorsResolverFailed::class,

        OperationSecurityMissing::class,

        MetaUnknownRule::class,
        MetaNoSuppressionReason::class,
        HeaderInvalidName::class,
        ExternaldocsInvalidUrl::class,
        ThrowsTransitiveMissing::class,
        DeprecatedAttribute::class,
        FieldNoEffect::class,
        ParameterExampleConflict::class,

        OperationTagMissing::class,
        TagUndeclaredAtRoot::class,
        ComponentOrphaned::class,
        RequestBodyNoContent::class,
        RequestBodyOnGetOrDelete::class,
        StreamingNoContentType::class,
        ResponseStatusUnconventional::class,
        PathTrailingSlashInconsistent::class,
        OperationIdInvalidChars::class,
        OperationIdNamingInconsistent::class,
        FieldNameNamingInconsistent::class,
        PathSegmentNamingInconsistent::class,
        ParameterNameNamingInconsistent::class,
        TagNameNamingInconsistent::class,
        HeaderNameNamingInconsistent::class,
        ComponentNameNamingInconsistent::class,
        ParameterQueryArrayNoExplode::class,
        SchemaNullableViaDeprecatedKeyword::class,
        ResponseNoError::class,
        ScopeOverlyBroad::class,
        MetaTooManySuppressions::class,

        SummaryMissing::class,

        TagsNoDescription::class,
        HeaderDescriptionMissing::class,
        DeprecatedNoReplacement::class,
        SchemaExampleMissing::class,
        OperationDescriptionMissing::class,
        OperationSummaryEqualsDescription::class,
        ActionMissingReturnType::class,
        EnumValuesUndocumented::class,
        WebhookDescriptionMissing::class,
        DeprecatedNoSunsetDate::class,
        ParameterDescriptionMissing::class,
        RequestBodyDescriptionMissing::class,
        FieldDescriptionMissing::class,
        SchemaDescriptionMissing::class,
        InfoDescriptionMissing::class,

        ParameterExampleMissing::class,
        ResponseExampleMissing::class,
        RequestBodyExampleMissing::class,
        InfoMetadataIncomplete::class,
        SchemaConstraintsMissing::class,

        HideExposeConflict::class,
        VisibilityAttributeNoOp::class,

        SpecUnknownReference::class,
        SpecRouteOrphaned::class,
        SpecConfigOrphaned::class,

        ResponseRefUnresolvable::class,

        OverridesUnknownField::class,
        OverridesUnused::class,
    ];

    /**
     * Builds, populates, and seals the registry.
     *
     * The body is the authoritative stage order, read top-to-bottom. The plugin list and envelope
     * resolver are passed in as class-strings, so this class never references a concrete plugin.
     *
     * @param array<class-string<Plugin>>         $plugins
     * @param array<class-string<Rule>>           $configRules
     * @param class-string<ErrorResponseResolver> $errorEnvelopeResolver
     *
     * @throws BindingResolutionException when a plugin class cannot be resolved from the container
     */
    public static function assemble(
        Container $container,
        array $plugins,
        array $configRules,
        string $errorEnvelopeResolver,
    ): OpenApiRegistry {
        $registry = new OpenApiRegistry();

        // Pre-plugin baseline stages: build the operation skeleton and emit contributions.
        $registry->addStage(RootStage::class);
        $registry->addStage(PathsStage::class);
        $registry->addStage(ErrorResponseInferenceStage::class);

        // Plugins mutate operations and contribute schemas, in the order given.
        foreach ($plugins as $pluginClass) {
            $container->make($pluginClass)->register($registry);
        }

        // Post-plugin stages: the ComponentsStage flush runs *after* the plugin loop, so late
        // contributors register schemas like any other. The two terminal stages run last; the
        // config-driven override escape hatch beats plugin and convention values, then
        // user-registered document transformers get the final word.
        $registry->addStage(ComponentsStage::class);
        $registry->addStage(SecurityStage::class);
        $registry->addStage(OverridesStage::class);
        $registry->addStage(TransformersStage::class);

        self::registerRules($registry);

        foreach ($configRules as $ruleClass) {
            $registry->addRule($ruleClass);
        }

        $registry->addErrorResponseResolver($errorEnvelopeResolver);

        // Build-once, then read-only: no further registration is accepted out-of-band.
        $registry->seal();

        return $registry;
    }

    private static function registerRules(OpenApiRegistry $registry): void
    {
        foreach (self::RULES as $rule) {
            $registry->addRule($rule);
        }
    }
}
