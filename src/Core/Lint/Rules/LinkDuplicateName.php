<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Attributes\Link;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use ReflectionAttribute;

use function array_count_values;
use function array_map;
use function sprintf;

/**
 * Reports when a controller method has multiple `#[Link]` attributes with the same name. Each
 * link name must be unique within the same operation.
 */
final class LinkDuplicateName implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->descriptor === null || $operation->descriptor->method === null) {
            return;
        }

        $attributes = $operation->descriptor->method->getAttributes(Link::class);

        if ($attributes === []) {
            return;
        }

        $names = array_map(
            static fn(ReflectionAttribute $attr): string => $attr->newInstance()->name,
            $attributes,
        );

        $counts = array_count_values($names);

        foreach ($counts as $name => $count) {
            if ($count <= 1) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Link name "%s" is declared %d times on %s::%s()',
                    $name,
                    $count,
                    $operation->descriptor->controller?->getName() ?? '(unknown)',
                    $operation->descriptor->method->getName(),
                ),
                fixHint: 'Remove the duplicate #[Link] attribute or use a different name.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'link.duplicate-name';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Two links on the same response share the same name.';
    }
}
