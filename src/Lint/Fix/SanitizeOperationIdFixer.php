<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Override;
use Radiergummi\OpenApi\Attributes\Operation;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Ast\SetAttributeArgument;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use ReflectionException;
use ReflectionMethod;

use function is_a;
use function is_string;
use function sprintf;

/**
 * Rewrites a codegen-unsafe operationId to the sanitised form on the source `#[Operation]` attribute.
 *
 * The rule stamps the sanitised operationId (via {@see \Radiergummi\OpenApi\Support\Generator\OperationIdDeriver::sanitise()})
 * onto the finding. An invalid operationId can only have come from an explicit attribute, so the
 * fixer always targets the existing `#[Operation]` attribute and sets its `operationId:` argument.
 * Degrades to nothing when the source member, the stamped value, or the attribute is unavailable.
 *
 * @internal
 */
final readonly class SanitizeOperationIdFixer implements Fixer
{
    public const string CONTEXT_OPERATION_ID = 'fixOperationId';

    /**
     * @return iterable<Fix>
     */
    #[Override]
    public function fix(Finding $finding, FixContext $context): iterable
    {
        $class = $finding->context[Finding::CONTEXT_SOURCE_CLASS] ?? null;
        $member = $finding->context[Finding::CONTEXT_SOURCE_MEMBER] ?? null;
        $operationId = $finding->context[self::CONTEXT_OPERATION_ID] ?? null;

        if (!is_string($class) || !is_string($member) || !is_string($operationId) || $operationId === '') {
            return [];
        }

        [$file, $index] = $this->locateOperationAttribute($class, $member);

        if ($file === null || $index === null) {
            return [];
        }

        return [new Fix(
            file: $file,
            description: sprintf('Sanitise operationId to "%s" on %s::%s', $operationId, $class, $member),
            ruleId: $finding->ruleId,
            operation: new SetAttributeArgument(
                target: new TargetSelector($class, TargetKind::Method, $member),
                attributeIndex: $index,
                argumentName: 'operationId',
                value: $operationId,
            ),
        )];
    }

    /**
     * The declaring file and flat, source-order position of the method's `#[Operation]` attribute.
     * Returns `[null, null]` when the method cannot be reflected or carries no `#[Operation]`.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function locateOperationAttribute(string $class, string $member): array
    {
        try {
            $reflector = new ReflectionMethod($class, $member);
        } catch (ReflectionException) {
            return [null, null];
        }

        $file = $reflector->getFileName() ?: null;

        foreach ($reflector->getAttributes() as $index => $attribute) {
            if (is_a($attribute->getName(), Operation::class, true)) {
                return [$file, $index];
            }
        }

        return [$file, null];
    }
}
