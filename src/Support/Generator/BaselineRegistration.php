<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Radiergummi\OpenApi\Contracts\Lint\Rule;
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
use Radiergummi\OpenApi\Lint\Rules\PublicEndpointContradictsMw;
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
use Radiergummi\OpenApi\Support\Generator\Stages\PathsStage;
use Radiergummi\OpenApi\Support\Generator\Stages\RootStage;
use Radiergummi\OpenApi\Support\Generator\Stages\SecurityStage;

/**
 * Registers the library's baseline pipeline stages — the generator infrastructure that runs
 * regardless of which plugins (Core or otherwise) are enabled.
 *
 * Runs first, before {@see \Radiergummi\OpenApi\Core\CorePlugin} and any user-configured
 * plugins. Stage order is load-bearing:
 *
 * 1. `RootStage` — populate document root (info, servers, tags, security schemes).
 * 2. `PathsStage` — assemble operation objects per route.
 * 3. `ErrorResponseInferenceStage` — drive registered `ErrorResponseContributor`s + the
 *    `ErrorResponseResolver` chain, writing inferred 4xx/5xx responses into operations.
 *    Must run after paths exist (it reads them) but before `ComponentsStage` flushes named
 *    response components into the document.
 * 4. `ComponentsStage` — flush component schemas/responses into the assembled document.
 * 5. `SecurityStage` — finalise top-level security requirements.
 *
 * Plugins that only contribute `ErrorResponseContributor`s (the most common case for new
 * envelope shapes) can therefore work without re-registering or re-implementing this
 * pipeline.
 *
 * Also registers the lint rules whose findings are emitted by baseline stages — currently
 * `errors.resolver-failed`, emitted by `ErrorResponseInferenceStage` when an
 * `ErrorResponseResolver` throws. Tying these rule registrations to the baseline avoids the
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
        PublicEndpointContradictsMw::class,
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
    ];

    public static function register(OpenApiRegistry $registry): void
    {
        $registry->addStage(RootStage::class);
        $registry->addStage(PathsStage::class);
        $registry->addStage(ErrorResponseInferenceStage::class);
        $registry->addStage(ComponentsStage::class);
        $registry->addStage(SecurityStage::class);

        foreach (self::RULES as $rule) {
            $registry->addRule($rule);
        }
    }
}
