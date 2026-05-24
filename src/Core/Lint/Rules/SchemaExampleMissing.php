<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;

use function is_array;
use function sprintf;

/**
 * Reports component schemas that have no example value.
 *
 * Examples help API consumers understand the expected format and content of data structures. This
 * rule checks for either the `example` (singular) or `examples` (plural, OAS 3.1) property on each
 * component schema. Schemas that declare an `enum` are exempt — their allowed values already
 * document the expected content.
 */
final class SchemaExampleMissing implements Rule, ComponentSchemaRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkComponentSchema(
        ComponentSchemaNode $componentSchema,
        LintContext $context,
    ): iterable {
        $schema = $componentSchema->raw;

        if ($schema === null) {
            return;
        }

        $hasExample = $schema->example !== Generator::UNDEFINED && $schema->example !== null;

        $hasExamples
            = $schema->examples !== Generator::UNDEFINED
            && is_array($schema->examples)
            && $schema->examples !== [];

        $hasEnum
            = $schema->enum !== Generator::UNDEFINED
            && is_array($schema->enum)
            && $schema->enum !== [];

        if ($hasExample || $hasExamples || $hasEnum) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf('Schema "%s" has no example value', $componentSchema->name),
            location: new FindingLocation(
                jsonPointer: '#/components/schemas/' . $componentSchema->name,
            ),
            fixHint: 'Add an "example" or "examples" property to the schema to improve documentation.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'schema.example-missing';
    }

    #[Override]
    public function level(): int
    {
        return 4;
    }

    #[Override]
    public function description(): string
    {
        return 'Schema property has no example value.';
    }
}
