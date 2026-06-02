<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use BackedEnum;
use Override;
use Radiergummi\OpenApi\Attributes\Tag;
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

final class TagDuplicate implements FixableRule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        // Detect on the source attributes via reflection, not on $operation->tags: the generator
        // deduplicates tags (OperationBuilder::mergeTags) before they reach the spec, so a repeated
        // #[Tag] never survives into the document. Reading the method's attributes is the only place
        // the redundancy is still visible — and it matches what the method-scoped fixer removes.
        if ($operation->descriptor?->method === null) {
            return;
        }

        $attributes = $operation->descriptor->method->getAttributes(Tag::class);

        if ($attributes === []) {
            return;
        }

        $names = array_map(
            static fn(ReflectionAttribute $attribute): string => self::tagName($attribute->newInstance()) ?? '',
            $attributes,
        );

        $counts = array_count_values($names);

        foreach ($counts as $tag => $count) {
            if ($count <= 1) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Tag "%s" is applied %d times on %s %s',
                    $tag,
                    $count,
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Remove the duplicate #[Tag] attribute.',
                context: RemoveAttributeFixer::contextForOperation($operation->descriptor, (string) $tag),
            );
        }
    }

    #[Override]
    public function fixer(): Fixer
    {
        return new RemoveAttributeFixer(
            attribute: Tag::class,
            member: MemberKind::Method,
            mode: RemoveMode::Dedupe,
            discriminator: self::tagName(...),
        );
    }

    private static function tagName(object $attr): ?string
    {
        if (!$attr instanceof Tag) {
            return null;
        }

        if ($attr->name instanceof BackedEnum) {
            return (string) $attr->name->value;
        }

        return $attr->name;
    }

    #[Override]
    public function id(): string
    {
        return 'tag.duplicate';
    }

    #[Override]
    public function level(): int
    {
        // Hygiene, not correctness: the generator deduplicates tags, so the emitted document is
        // always valid — a repeated #[Tag] is a redundant source attribute that changes nothing in
        // the output. Level 3 (Inconsistent), alongside the other no-op-attribute removal rules.
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'The same #[Tag] is applied more than once to a controller method.';
    }
}
