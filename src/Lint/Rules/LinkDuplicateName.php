<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\Link;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
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
 * Reports when a controller method has multiple `#[Link]` attributes with the same name. Each
 * link name must be unique within the same operation.
 */
final class LinkDuplicateName implements FixableRule, OperationRuleVisitor
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
            static fn(ReflectionAttribute $attribute): string => $attribute->newInstance()->name,
            $attributes,
        );

        $counts = array_count_values($names);

        foreach ($counts as $name => $count) {
            if ($count <= 1) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                severity: $this->severity(),
                message: sprintf(
                    'Link name "%s" is declared %d times on %s::%s()',
                    $name,
                    $count,
                    $operation->descriptor->controller?->getName() ?? '(unknown)',
                    $operation->descriptor->method->getName(),
                ),
                fixHint: 'Remove the duplicate #[Link] attribute or use a different name.',
                context: RemoveAttributeFixer::contextForOperation($operation->descriptor, (string) $name),
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'link.duplicate-name';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Broken;
    }

    #[Override]
    public function fixer(): Fixer
    {
        return new RemoveAttributeFixer(
            attribute: Link::class,
            member: MemberKind::Method,
            mode: RemoveMode::Dedupe,
            discriminator: static fn(object $attr): ?string
                => $attr instanceof Link
                ? $attr->name
                : null,
        );
    }

    #[Override]
    public function description(): string
    {
        return 'Two links on the same response share the same name.';
    }
}
