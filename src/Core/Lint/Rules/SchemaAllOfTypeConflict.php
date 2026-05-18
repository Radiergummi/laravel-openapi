<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ComponentSchemaRule as ComponentSchemaRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ComponentSchemaNode;

use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Reports allOf schemas that combine sub-schemas with incompatible types.
 *
 * When an allOf composition declares sub-schemas with conflicting type declarations (e.g. `string`
 * and `integer`), the resulting schema is impossible to satisfy.
 */
final class SchemaAllOfTypeConflict implements Rule, ComponentSchemaRuleVisitor
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

        yield from $this->checkSchema(
            $schema,
            '#/components/schemas/' . $componentSchema->name,
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
            if ($subSchema === Generator::UNDEFINED) {
                continue;
            }

            $type = $subSchema->type;

            if ($type === Generator::UNDEFINED || $type === null) {
                continue;
            }

            // "null" is not a conflicting type: `allOf: [{type: string}, {type: "null"}]`
            // is the OAS 3.1 idiom for a nullable string. Ignore it so the
            // nullable composition isn't flagged as an impossible schema.
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
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Schema "%s" has allOf with conflicting types: %s',
                $schemaName,
                implode(', ', $types),
            ),
            location: new FindingLocation(jsonPointer: "{$pointer}/allOf"),
            fixHint: 'Ensure all sub-schemas in allOf use compatible types.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'schema.allof-type-conflict';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'allOf members declare conflicting type values.';
    }
}
