<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;

use function array_find;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Reports schemas that declare a broad type but omit the constraint that bounds it: strings
 * without `maxLength`, arrays without `maxItems`, and integers/numbers without `minimum` or
 * `maximum`.
 *
 * This rule is intentionally noisy — it fires on every unconstrained field — and is therefore
 * shipped DISABLED by default (see config/openapi.php `lint.disabled_rules`). Teams that want to
 * enforce constraint coverage can opt in explicitly.
 *
 * Exemptions:
 *   - Strings with a `format` (already constrained by the format semantics).
 *   - Strings with an `enum` (values are fully enumerated).
 *   - Fields whose `raw` is null (no OA annotation available to inspect).
 */
final class SchemaConstraintsMissing implements Rule, FieldRuleVisitor, ComponentSchemaRuleVisitor
{
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
     * Shared logic used by both checkField and checkComponentSchema.
     *
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
            // Strings with a format or enum are already sufficiently constrained.
            if ($format !== null) {
                return;
            }

            if ($enum !== null && $enum !== []) {
                return;
            }

            if ($raw->maxLength !== Generator::UNDEFINED) {
                return;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf('String schema "%s" has no maxLength constraint', $name),
                location: $loc,
                fixHint: 'Add a "maxLength" to cap the string length, or add a "format" or "enum" if the values are bounded by other means.',
            );

            return;
        }

        if ($type === 'array') {
            if ($raw->maxItems !== Generator::UNDEFINED) {
                return;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf('Array schema "%s" has no maxItems constraint', $name),
                location: $loc,
                fixHint: 'Add a "maxItems" to prevent unbounded arrays from being accepted or returned.',
            );

            return;
        }

        if ($type === 'integer' || $type === 'number') {
            if (
                $raw->minimum !== Generator::UNDEFINED
                || $raw->maximum !== Generator::UNDEFINED
            ) {
                return;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf('Numeric schema "%s" has no minimum or maximum constraint', $name),
                location: $loc,
                fixHint: 'Add a "minimum" and/or "maximum" to bound the numeric range.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'schema.constraints-missing';
    }

    #[Override]
    public function level(): int
    {
        return 4;
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

        $rawType = $schema->type !== Generator::UNDEFINED ? $schema->type : null;

        if (is_array($rawType)) {
            $type = array_find($rawType, static fn(string $t): bool => $t !== 'null');
        } elseif (is_string($rawType)) {
            $type = $rawType;
        } else {
            $type = null;
        }
        $format = $schema->format !== Generator::UNDEFINED ? $schema->format : null;
        $enum = $schema->enum !== Generator::UNDEFINED && is_array($schema->enum)
            ? $schema->enum
            : null;

        yield from $this->inspectSchema(
            name: $componentSchema->name,
            type: $type,
            format: $format,
            enum: $enum,
            raw: $schema,
            location: new FindingLocation(
                jsonPointer: '#/components/schemas/' . $componentSchema->name,
            ),
        );
    }

    #[Override]
    public function description(): string
    {
        return 'A string has no maxLength, an array no maxItems, or a number no bounds.';
    }
}
