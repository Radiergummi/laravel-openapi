<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Registry;

use Radiergummi\OpenApi\Core\Extractors\FormRequestRequestSchemaResolver;
use Radiergummi\OpenApi\Core\Extractors\PaginatorResponseResolver;
use Radiergummi\OpenApi\Core\Generator\CoreQueryParameterResolver;
use Radiergummi\OpenApi\Core\Lint\Rules;

/**
 * Registers the framework-agnostic built-ins — the core request-schema
 * resolver and every core lint rule — into the registry. Runs first, before
 * plugins and config extras.
 */
final class CoreRegistration
{
    /**
     * Every core lint rule. Moved here verbatim from the former
     * `OpenApiServiceProvider::RULES` constant.
     *
     * Rules are listed here for registration only. The authoritative severity
     * of each rule is its own `level()` method and is visible via
     * `php artisan openapi:lint --list`.
     *
     * @var list<class-string>
     */
    public const array RULES = [
        Rules\SpecInvalid::class,
        Rules\LinkInvalidOperation::class,
        Rules\LinkInvalidParameter::class,
        Rules\LinkParameterRequiredMissing::class,
        Rules\DiscriminatorInvalidMapping::class,
        Rules\SecurityInvalidScope::class,
        Rules\ResponseDuplicateStatus::class,
        Rules\QueryParamDuplicate::class,
        Rules\FieldConflictingType::class,
        Rules\FieldInvalidFormat::class,
        Rules\FieldEnumMismatch::class,

        Rules\ServerInvalidUrl::class,
        Rules\ServerVariableUndeclared::class,

        Rules\OperationIdDuplicate::class,
        Rules\RefBroken::class,
        Rules\PathParameterUndeclared::class,
        Rules\PathParameterUndefined::class,
        Rules\ParameterPathMustBeRequired::class,
        Rules\ParameterDuplicateName::class,
        Rules\LinkBothOperationIdAndRef::class,
        Rules\LinkNeitherOperationIdNorRef::class,
        Rules\LinkDuplicateName::class,
        Rules\WebhookNameDuplicate::class,
        Rules\SecuritySchemeUndefined::class,
        Rules\SchemaRequiredWithoutProperty::class,
        Rules\SchemaEnumEmpty::class,
        Rules\SchemaEnumTypeMismatch::class,
        Rules\PublicEndpointContradictsMw::class,
        Rules\ResponseNoSuccess::class,
        Rules\ResponseRedirectWithoutLocation::class,
        Rules\OperationIdMissing::class,
        Rules\SchemaAllOfTypeConflict::class,
        Rules\ParameterQueryNoSchema::class,
        Rules\TagDuplicate::class,
        Rules\ResponseDescriptionMissing::class,

        Rules\RequestEmpty::class,
        Rules\ThrowsUnmapped::class,
        Rules\RuleUnknown::class,

        Rules\OperationSecurityMissing::class,

        Rules\MetaUnknownRule::class,
        Rules\MetaNoSuppressionReason::class,
        Rules\HeaderInvalidName::class,
        Rules\ExternaldocsInvalidUrl::class,
        Rules\ThrowsTransitiveMissing::class,
        Rules\DeprecatedAttribute::class,
        Rules\FieldNoEffect::class,
        Rules\ParameterExampleConflict::class,

        Rules\OperationTagMissing::class,
        Rules\TagUndeclaredAtRoot::class,
        Rules\ComponentOrphaned::class,
        Rules\RequestBodyNoContent::class,
        Rules\RequestBodyOnGetOrDelete::class,
        Rules\StreamingNoContentType::class,
        Rules\ResponseStatusUnconventional::class,
        Rules\PathTrailingSlashInconsistent::class,
        Rules\OperationIdInvalidChars::class,
        Rules\OperationIdNamingInconsistent::class,
        Rules\FieldNameNamingInconsistent::class,
        Rules\PathSegmentNamingInconsistent::class,
        Rules\ParameterNameNamingInconsistent::class,
        Rules\TagNameNamingInconsistent::class,
        Rules\HeaderNameNamingInconsistent::class,
        Rules\ComponentNameNamingInconsistent::class,
        Rules\ParameterQueryArrayNoExplode::class,
        Rules\SchemaNullableViaDeprecatedKeyword::class,
        Rules\ResponseNoError::class,
        Rules\ScopeOverlyBroad::class,
        Rules\MetaTooManySuppressions::class,

        Rules\SummaryMissing::class,

        Rules\TagsNoDescription::class,
        Rules\HeaderDescriptionMissing::class,
        Rules\DeprecatedNoReplacement::class,
        Rules\SchemaExampleMissing::class,
        Rules\OperationDescriptionMissing::class,
        Rules\OperationSummaryEqualsDescription::class,
        Rules\EnumValuesUndocumented::class,
        Rules\WebhookDescriptionMissing::class,
        Rules\DeprecatedNoSunsetDate::class,
        Rules\ParameterDescriptionMissing::class,
        Rules\RequestBodyDescriptionMissing::class,
        Rules\FieldDescriptionMissing::class,
        Rules\SchemaDescriptionMissing::class,
        Rules\InfoDescriptionMissing::class,

        Rules\ParameterExampleMissing::class,
        Rules\ResponseExampleMissing::class,
        Rules\RequestBodyExampleMissing::class,
        Rules\InfoMetadataIncomplete::class,
        Rules\SchemaConstraintsMissing::class,

        Rules\HideExposeConflict::class,
        Rules\VisibilityAttributeNoOp::class,

        Rules\SpecUnknownReference::class,
        Rules\SpecRouteOrphaned::class,
        Rules\SpecConfigOrphaned::class,
    ];

    public static function register(OpenApiRegistry $registry): void
    {
        $registry->addRequestSchemaResolver(FormRequestRequestSchemaResolver::class);
        $registry->addQueryParameterResolver(CoreQueryParameterResolver::class);
        $registry->addPrimaryResponseResolver(PaginatorResponseResolver::class);

        foreach (self::RULES as $rule) {
            $registry->addRule($rule);
        }
    }
}
