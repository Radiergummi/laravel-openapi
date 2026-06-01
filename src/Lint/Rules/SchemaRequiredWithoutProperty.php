<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ComponentSchemaNode;
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;

use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;
use function sprintf;

/**
 * Reports when a schema declares a property name in `required` that does not
 * exist in the schema's `properties` list.
 */
final class SchemaRequiredWithoutProperty implements Rule, ComponentSchemaRuleVisitor
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

        $required = $schema->required;

        if (is_undefined($required) || !is_array($required)) {
            return;
        }

        $propertyNames = $this->collectPropertyNames($componentSchema, $context);

        foreach ($required as $requiredName) {
            if (in_array($requiredName, $propertyNames, true)) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Schema "%s" marks "%s" as required, but no such property is defined',
                    $componentSchema->name,
                    $requiredName,
                ),
                location: new FindingLocation(
                    jsonPointer: $componentSchema->pointer('required'),
                ),
                fixHint: sprintf(
                    'Add a "%s" property to the schema or remove it from the required list.',
                    $requiredName,
                ),
            );
        }
    }

    /**
     * Collect all property names reachable from a component schema, including
     * those inherited through `allOf` composition (resolving `$ref` links to
     * other component schemas).
     *
     * @return list<string>
     */
    private function collectPropertyNames(
        ComponentSchemaNode $componentSchema,
        LintContext $context,
    ): array {
        $names = [];
        $this->collectFromSchema($componentSchema->raw, $context, $names, []);

        return $names;
    }

    /**
     * @param list<string>        $names   Accumulated property names (by reference)
     * @param array<string, true> $visited Component names already resolved (cycle guard)
     */
    private function collectFromSchema(
        ?OA\Schema $schema,
        LintContext $context,
        array &$names,
        array $visited,
    ): void {
        if ($schema === null) {
            return;
        }

        $properties = $schema->properties;

        if (is_array($properties)) {
            foreach ($properties as $property) {
                if (
                    is_defined($property)
                    && is_defined($property->property)
                ) {
                    $names[] = $property->property;
                }
            }
        }

        $allOf = $schema->allOf;

        if (!is_array($allOf)) {
            return;
        }

        foreach ($allOf as $sub) {
            if (is_undefined($sub)) {
                continue;
            }

            $refName = is_string($sub->ref) ? $this->refToName($sub->ref) : null;

            if ($refName === null) {
                $this->collectFromSchema($sub, $context, $names, $visited);

                continue;
            }

            if (isset($visited[$refName])) {
                continue;
            }

            $visited[$refName] = true;
            $component = $context->index->componentsByName[$refName] ?? null;

            if ($component !== null) {
                $this->collectFromSchema($component->raw, $context, $names, $visited);
            }
        }
    }

    /**
     * Resolve a local schema `$ref` (`#/components/schemas/Foo`) to its name.
     */
    private function refToName(string $ref): ?string
    {
        if (preg_match('~^#/components/schemas/(.+)$~', $ref, $matches)) {
            return $matches[1];
        }

        return null;
    }

    #[Override]
    public function id(): string
    {
        return 'schema.required-without-property';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'required names a field not in properties.';
    }
}
