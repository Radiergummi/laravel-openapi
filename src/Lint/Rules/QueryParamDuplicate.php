<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\QueryParam;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\Fix\MemberKind;
use Radiergummi\OpenApi\Lint\Fix\RemoveAttributeFixer;
use Radiergummi\OpenApi\Lint\Fix\RemoveMode;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use ReflectionAttribute;

use function array_count_values;
use function array_map;
use function sprintf;

/**
 * Reports duplicate `#[QueryParam]` attributes within a single operation.
 *
 * Query parameter names must be unique per operation.
 */
final class QueryParamDuplicate implements FixableRule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        // Detect on the source attributes via reflection, not on $operation->queryParameters: the
        // query-parameter resolver keys merged parameters by name (last-wins), so a duplicate
        // #[QueryParam('q')] silently drops the earlier declaration before it reaches the spec.
        // Reflection on the method is where that data loss is still visible — and what the fixer removes.
        if ($operation->descriptor?->method === null) {
            return;
        }

        $attributes = $operation->descriptor->method->getAttributes(QueryParam::class);

        if ($attributes === []) {
            return;
        }

        $names = array_map(
            static fn(ReflectionAttribute $attribute): string => $attribute->newInstance()->name,
            $attributes,
        );

        $counts = array_count_values($names);

        foreach ($counts as $name => $occurrenceCount) {
            if ($occurrenceCount < 2) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Query parameter "%s" is declared %d times on %s %s',
                    $name,
                    $occurrenceCount,
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Remove the duplicate query parameter declaration; names must be unique per operation.',
                context: RemoveAttributeFixer::contextForOperation($operation->descriptor, (string) $name),
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'queryparam.duplicate';
    }

    #[Override]
    public function level(): int
    {
        // Degraded, not broken: the emitted document is valid (the resolver keeps one parameter per
        // name), but the last-wins merge silently discards the earlier #[QueryParam]'s details —
        // the document drops information the author declared. Level 1.
        return 1;
    }

    #[Override]
    public function fixer(): Fixer
    {
        return new RemoveAttributeFixer(
            attribute: QueryParam::class,
            member: MemberKind::Method,
            mode: RemoveMode::Dedupe,
            discriminator: static fn(object $attr): ?string
                => $attr instanceof QueryParam
                ? $attr->name
                : null,
        );
    }

    #[Override]
    public function description(): string
    {
        return 'Two #[QueryParam] attributes on the same controller method share the same name.';
    }
}
