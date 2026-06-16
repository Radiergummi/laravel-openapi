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
use Radiergummi\OpenApi\Lint\Visitors\Finalizable;
use Radiergummi\OpenApi\Lint\Visitors\Resettable;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;

use function is_array;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;
use function sprintf;
use function str_starts_with;

/**
 * Reports when a schema's `discriminator.mapping` references a schema that does not declare the
 * discriminator's `propertyName` in its properties.
 */
final class DiscriminatorInvalidMapping implements Rule, ComponentSchemaRuleVisitor, Finalizable, Resettable
{
    /** @var array<string, OA\Schema> */
    private array $schemaMap = [];

    /** @var list<OA\Schema> */
    private array $pending = [];

    #[Override]
    public function reset(): void
    {
        $this->schemaMap = [];
        $this->pending = [];
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
            return [];
        }

        if (is_defined($schema->schema) && $schema->schema !== null) {
            $this->schemaMap[$schema->schema] = $schema;
        }

        if (
            is_defined($schema->discriminator)
            && $schema->discriminator !== null
            && is_defined($schema->discriminator->propertyName)
            && is_array($schema->discriminator->mapping)
        ) {
            $this->pending[] = $schema;
        }

        return [];
    }

    /** @return iterable<Finding> */
    #[Override]
    public function finalize(LintContext $context): iterable
    {
        foreach ($this->pending as $schema) {
            yield from $this->checkSchema($schema, $this->schemaMap);
        }
    }

    /**
     * @param array<string, OA\Schema> $schemaMap
     *
     * @return iterable<Finding>
     */
    private function checkSchema(OA\Schema $schema, array $schemaMap): iterable
    {
        $discriminator = $schema->discriminator;
        $propertyName = $discriminator->propertyName;
        $baseSchemaName = is_defined($schema->schema) ? $schema->schema : '(unknown)';

        foreach ($discriminator->mapping as $discriminatorValue => $ref) {
            if (!is_string($ref)) {
                continue;
            }

            $targetSchemaName = $this->resolveRefToSchemaName($ref);

            if ($targetSchemaName === null) {
                continue;
            }

            $targetSchema = $schemaMap[$targetSchemaName] ?? null;

            if ($targetSchema === null) {
                yield new Finding(
                    ruleId: $this->id(),
                    severity: $this->severity(),
                    message: sprintf(
                        'Discriminator mapping "%s" on schema "%s" references unknown schema "%s"',
                        $discriminatorValue,
                        $baseSchemaName,
                        $targetSchemaName,
                    ),
                    location: new FindingLocation(
                        jsonPointer: sprintf(
                            '#/components/schemas/%s/discriminator/mapping/%s',
                            $baseSchemaName,
                            $discriminatorValue,
                        ),
                    ),
                    fixHint: 'Ensure the $ref points to an existing component schema.',
                );

                continue;
            }

            if (!$this->schemaHasProperty($targetSchema, $propertyName, $schemaMap)) {
                yield new Finding(
                    ruleId: $this->id(),
                    severity: $this->severity(),
                    message: sprintf(
                        'Schema "%s" mapped by discriminator on "%s" does not declare property "%s"',
                        $targetSchemaName,
                        $baseSchemaName,
                        $propertyName,
                    ),
                    location: new FindingLocation(
                        jsonPointer: sprintf(
                            '#/components/schemas/%s/discriminator/mapping/%s',
                            $baseSchemaName,
                            $discriminatorValue,
                        ),
                    ),
                    fixHint: sprintf(
                        'Add a "%s" property to the "%s" schema.',
                        $propertyName,
                        $targetSchemaName,
                    ),
                );
            }
        }
    }

    /** Resolve a `$ref` string like `#/components/schemas/Foo` to the schema key `Foo`. */
    private function resolveRefToSchemaName(string $ref): ?string
    {
        $name = ComponentReference::name($ref);

        if ($name !== null) {
            return $name;
        }

        // Plain schema name without a $ref prefix.
        if (!str_starts_with($ref, '#/') && !str_starts_with($ref, '/')) {
            return $ref;
        }

        return null;
    }

    #[Override]
    public function id(): string
    {
        return 'discriminator.invalid-mapping';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Broken;
    }

    /**
     * @param array<string, OA\Schema> $schemaMap
     * @param array<string, true>      $visited   cycle guard
     */
    private function schemaHasProperty(
        OA\Schema $schema,
        string $propertyName,
        array $schemaMap,
        array $visited = [],
    ): bool {
        if (is_array($schema->properties)) {
            foreach ($schema->properties as $property) {
                if (is_undefined($property)) {
                    continue;
                }

                if (
                    is_defined($property->property)
                    && $property->property === $propertyName
                ) {
                    return true;
                }
            }
        }

        if (!is_array($schema->allOf)) {
            return false;
        }

        foreach ($schema->allOf as $sub) {
            if (is_undefined($sub)) {
                continue;
            }

            $ref = $sub->ref;

            if (is_defined($ref) && is_string($ref)) {
                $refName = $this->resolveRefToSchemaName($ref);

                if ($refName !== null) {
                    if (isset($visited[$refName])) {
                        continue;
                    }

                    $visited[$refName] = true;
                    $resolved = $schemaMap[$refName] ?? null;

                    if ($resolved !== null && $this->schemaHasProperty(
                        $resolved,
                        $propertyName,
                        $schemaMap,
                        $visited,
                    )) {
                        return true;
                    }
                }

                continue;
            }

            $subName = is_defined($sub->schema) ? $sub->schema : null;

            if ($subName !== null) {
                if (isset($visited[$subName])) {
                    continue;
                }

                $visited[$subName] = true;
            }

            if ($this->schemaHasProperty($sub, $propertyName, $schemaMap, $visited)) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public function description(): string
    {
        return 'Discriminator mapping references a missing component schema.';
    }
}
