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
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;

use function is_array;
use function Radiergummi\OpenApi\is_undefined;

/**
 * Reports schemas that declare an empty `enum` array, which makes the schema unsatisfiable: no
 * value can ever be valid.
 */
final class SchemaEnumEmpty implements Rule, FieldRuleVisitor, ComponentSchemaRuleVisitor
{
    public string $id = 'schema.enum-empty';
    public Severity $severity = Severity::Degraded;
    public string $description = 'A schema declares an empty enum (enum: []) and is unsatisfiable.';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkField(FieldNode $field, LintContext $context): iterable
    {
        if ($this->isEmptyEnum($field->enum)) {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    'Field "%s" declares an empty enum (enum: []) and is unsatisfiable',
                    $field->name,
                ),
                location: new FindingLocation(jsonPointer: $field->pointer('enum')),
                fixHint: 'Add at least one valid enum value or remove the enum constraint.',
            );
        }
    }

    /**
     * @param null|list<mixed>|mixed $enum
     */
    private function isEmptyEnum(mixed $enum): bool
    {
        return $enum === [];
    }



    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkComponentSchema(ComponentSchemaNode $componentSchema, LintContext $context): iterable
    {
        $raw = $componentSchema->raw;

        if ($raw === null) {
            return;
        }

        $enum = $raw->enum;

        if (is_undefined($enum) || !is_array($enum)) {
            return;
        }

        if ($this->isEmptyEnum($enum)) {
            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    'Component schema "%s" declares an empty enum (enum: []) and is unsatisfiable',
                    $componentSchema->name,
                ),
                location: new FindingLocation(jsonPointer: $componentSchema->pointer('enum')),
                fixHint: 'Add at least one valid enum value or remove the enum constraint.',
            );
        }
    }

}
