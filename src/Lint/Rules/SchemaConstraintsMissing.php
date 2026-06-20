<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations as OA;
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
use Radiergummi\OpenApi\Support\Generator\ComponentReference;

use function array_find;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function sprintf;

/**
 * Disabled by default (noisy); opt in via config.
 *
 * Exempt: strings with `format` or `enum`, fields with no OA annotation.
 */
final class SchemaConstraintsMissing implements Rule, FieldRuleVisitor, ComponentSchemaRuleVisitor
{
    public string $id = 'schema.constraints-missing';
    public Severity $severity = Severity::Improvable;
    public string $description = 'A string has no maxLength, an array no maxItems, or a number no bounds.';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkField(FieldNode $field, LintContext $context): iterable
    {
        if ($field->raw === null) {
            return;
        }

        yield from $this->inspectSchema(
            name: $field->name,
            type: $field->type,
            format: $field->format,
            enum: $field->enum,
            raw: $field->raw,
            location: null,
        );
    }

    /**
     * @param null|array<mixed> $enum
     *
     * @return iterable<Finding>
     */
    private function inspectSchema(
        string $name,
        ?string $type,
        ?string $format,
        ?array $enum,
        OA\Schema|OA\Property $raw,
        ?FindingLocation $location,
    ): iterable {
        $loc = $location ?? new FindingLocation();

        if ($type === 'string') {
            if ($format !== null) {
                return;
            }

            if ($enum !== null && $enum !== []) {
                return;
            }

            if (is_defined($raw->maxLength)) {
                return;
            }

            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf('String schema "%s" has no maxLength constraint', $name),
                location: $loc,
                fixHint: 'Add a "maxLength" to cap the string length, or add a "format" or "enum" if the values are bounded by other means.',
            );

            return;
        }

        if ($type === 'array') {
            if (is_defined($raw->maxItems)) {
                return;
            }

            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf('Array schema "%s" has no maxItems constraint', $name),
                location: $loc,
                fixHint: 'Add a "maxItems" to prevent unbounded arrays from being accepted or returned.',
            );

            return;
        }

        if ($type === 'integer' || $type === 'number') {
            if (
                is_defined($raw->minimum)
                || is_defined($raw->maximum)
            ) {
                return;
            }

            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf('Numeric schema "%s" has no minimum or maximum constraint', $name),
                location: $loc,
                fixHint: 'Add a "minimum" and/or "maximum" to bound the numeric range.',
            );
        }
    }



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

        $rawType = is_defined($schema->type) ? $schema->type : null;

        if (is_array($rawType)) {
            $type = array_find($rawType, static fn(string $t): bool => $t !== 'null');
        } elseif (is_string($rawType)) {
            $type = $rawType;
        } else {
            $type = null;
        }
        $format = is_defined($schema->format) ? $schema->format : null;
        $enum = is_defined($schema->enum) && is_array($schema->enum)
            ? $schema->enum
            : null;

        yield from $this->inspectSchema(
            name: $componentSchema->name,
            type: $type,
            format: $format,
            enum: $enum,
            raw: $schema,
            location: new FindingLocation(
                jsonPointer: ComponentReference::pointer($componentSchema->name),
            ),
        );
    }

}
