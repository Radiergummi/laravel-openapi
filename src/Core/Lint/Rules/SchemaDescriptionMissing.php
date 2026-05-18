<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;

use function sprintf;
use function trim;

/**
 * Reports named component schemas that have no description.
 *
 * Named schemas in the components section are reused throughout the API.
 * A description on each schema makes generated documentation significantly
 * more useful for consumers.
 */
final class SchemaDescriptionMissing implements Rule, ComponentSchemaRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkComponentSchema(ComponentSchemaNode $componentSchema, LintContext $context): iterable
    {
        if ($componentSchema->description !== null && trim($componentSchema->description) !== '') {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf('Component schema "%s" has no description', $componentSchema->name),
            location: new FindingLocation(jsonPointer: $componentSchema->pointer()),
            fixHint: 'Add a description to the component schema explaining what it represents.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'schema.description-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'Named component schema has no description.';
    }
}
