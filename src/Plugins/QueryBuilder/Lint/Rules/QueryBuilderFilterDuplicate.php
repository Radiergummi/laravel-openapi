<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\QueryBuilder\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;

use function array_filter;
use function array_keys;
use function sprintf;

/**
 * Flags two or more `#[AllowedFilter]` attributes on the same action declaring the same wire name.
 * When names collide, `OperationBuilder`'s name+in dedup silently keeps only the last instance;
 * the earlier ones are dropped without any diagnostic.
 */
final readonly class QueryBuilderFilterDuplicate implements Rule, OperationRule
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $descriptor = $operation->descriptor;

        if ($operation->webhook || $descriptor === null) {
            return;
        }

        $nameCount = [];

        foreach ($descriptor->actionAttributes(AllowedFilter::class) as $attribute) {
            $name = $attribute->newInstance()->name;
            $nameCount[$name] = ($nameCount[$name] ?? 0) + 1;
        }

        $duplicatedNames = array_keys(array_filter($nameCount, static fn(int $count) => $count > 1));

        foreach ($duplicatedNames as $name) {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '#[AllowedFilter(\'%s\')] is declared %d times on %s %s — only the last instance is emitted',
                    $name,
                    $nameCount[$name],
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Remove the duplicate #[AllowedFilter] declarations, keeping the one with the intended type.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'query-builder.filter-duplicate';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Two or more #[AllowedFilter] attributes on the same action share the same name — only the last is emitted. (QueryBuilder plugin.)';
    }
}
