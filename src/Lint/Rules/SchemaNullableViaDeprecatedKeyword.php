<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;

use function sprintf;

/**
 * Reports fields that use the deprecated `nullable: true` keyword instead of the OAS 3.1
 * type-union pattern (`type: ['string', 'null']`).
 */
final class SchemaNullableViaDeprecatedKeyword implements Rule, FieldRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkField(FieldNode $field, LintContext $context): iterable
    {
        $raw = $field->raw;

        if ($raw === null) {
            return;
        }

        if ($raw->nullable === Generator::UNDEFINED || $raw->nullable !== true) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Field "%s" uses the deprecated nullable keyword; use type union with "null" instead',
                $field->name,
            ),
            fixHint: 'Replace nullable: true with a type union, e.g. type: ["string", "null"].',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'schema.nullable-via-deprecated-keyword';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Schema uses the deprecated OpenAPI 3.0 nullable: true keyword instead of a type array.';
    }
}
