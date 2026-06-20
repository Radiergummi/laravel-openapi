<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;

use function sprintf;
use function trim;

/**
 * Reports named component schemas that have no description.
 *
 * Schemas in the components section are reused throughout the API; a description on each makes
 * generated documentation significantly more useful for consumers.
 */
final class SchemaDescriptionMissing implements Rule, ComponentSchemaRuleVisitor
{
    public string $id = 'schema.description-missing';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'Named component schema has no description.';

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
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf('Component schema "%s" has no description', $componentSchema->name),
            location: new FindingLocation(jsonPointer: $componentSchema->pointer()),
            fixHint: 'Add a description to the component schema explaining what it represents.',
        );
    }



}
