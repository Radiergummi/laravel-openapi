<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;
use Radiergummi\OpenApi\Lint\Visitors\FieldRule as FieldRuleVisitor;

use function preg_match;
use function sprintf;
use function str_contains;
use function trim;

/**
 * Reports enum fields whose description does not document the enum values.
 *
 * When a field defines enum values, the description should explain what each value means. This
 * rule uses a simple heuristic: the description must contain at least one of the enum values as
 * a substring, or include a bullet/list pattern (lines starting with `-` or `*`).
 */
final class EnumValuesUndocumented implements Rule, FieldRuleVisitor
{
    private const LIST_PATTERN = "/^\s*[-*]\s+/m";

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkField(FieldNode $field, LintContext $context): iterable
    {
        if ($field->enum === null || $field->enum === []) {
            return;
        }

        $description = $field->description;

        if ($description === null || trim($description) === '') {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Enum field "%s" has no description documenting its values',
                    $field->name,
                ),
                fixHint: 'Add a description that explains each enum value.',
            );

            return;
        }

        if ($this->descriptionDocumentsValues($description, $field->enum)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Enum field "%s" description does not mention any of its values',
                $field->name,
            ),
            fixHint: 'Update the description to document the meaning of each enum value.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'enum.values-undocumented';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    /**
     * @param list<mixed> $enumValues
     */
    private function descriptionDocumentsValues(string $description, array $enumValues): bool
    {
        // Check if the description contains a bullet/list pattern
        if (preg_match(self::LIST_PATTERN, $description) === 1) {
            return true;
        }

        // Check if the description mentions at least one enum value
        return array_any(
            $enumValues,
            fn(mixed $value): bool => str_contains($description, (string) $value),
        );
    }

    #[Override]
    public function description(): string
    {
        return 'Enum field has no description explaining the allowed values.';
    }
}
