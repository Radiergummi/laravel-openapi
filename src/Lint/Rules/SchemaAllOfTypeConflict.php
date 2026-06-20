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
use Radiergummi\OpenApi\Lint\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;

use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_undefined;
use function sprintf;

/**
 * Reports allOf schemas whose sub-schemas declare conflicting types (e.g., `string` and `integer`),
 * making the composition impossible to satisfy.
 */
final class SchemaAllOfTypeConflict implements Rule, ComponentSchemaRuleVisitor
{
    public string $id = 'schema.allof-type-conflict';
    public Severity $severity = Severity::Degraded;
    public string $description = 'allOf members declare conflicting type values.';

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

        yield from $this->checkSchema(
            $schema,
            ComponentReference::pointer($componentSchema->name),
            $componentSchema->name,
        );
    }

    /**
     * @return iterable<Finding>
     */
    private function checkSchema(OA\Schema $schema, string $pointer, string $schemaName): iterable
    {
        $allOf = $schema->allOf;

        if (!is_array($allOf) || $allOf === []) {
            return;
        }

        /** @var list<string> $types */
        $types = [];

        foreach ($allOf as $subSchema) {
            if (is_undefined($subSchema)) {
                continue;
            }

            $type = $subSchema->type;

            if (is_undefined($type) || $type === null) {
                continue;
            }

            // `type: "null"` is the OAS 3.1 nullable idiom, not a conflicting type.
            if (is_array($type)) {
                foreach ($type as $t) {
                    if (is_string($t) && $t !== 'null' && !in_array($t, $types, true)) {
                        $types[] = $t;
                    }
                }
            } elseif (is_string($type) && $type !== 'null' && !in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        if (count($types) < 2) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Schema "%s" has allOf with conflicting types: %s',
                $schemaName,
                implode(', ', $types),
            ),
            location: new FindingLocation(jsonPointer: "{$pointer}/allOf"),
            fixHint: 'Ensure all sub-schemas in allOf use compatible types.',
        );
    }



}
