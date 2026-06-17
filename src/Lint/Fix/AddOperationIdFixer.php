<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Override;
use Radiergummi\OpenApi\Attributes\Operation;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Ast\AddAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\SetAttributeArgument;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use ReflectionException;
use ReflectionMethod;

use function is_a;
use function is_string;
use function sprintf;

/**
 * Adds a missing operationId to the source `#[Operation]` attribute.
 *
 * The rule stamps the operationId inference would have emitted (via {@see \Radiergummi\OpenApi\Support\Generator\OperationIdDeriver})
 * onto the finding, so the fix equals exactly what the generator produces. If the action method
 * already carries `#[Operation]`, the fixer sets its `operationId:` argument; otherwise it
 * synthesises a new `#[Operation(operationId: …)]`. Degrades to nothing when the source member or
 * the stamped operationId is unavailable.
 *
 * @internal
 */
final readonly class AddOperationIdFixer implements Fixer
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

        $target = new TargetSelector($class, TargetKind::Method, $member);
        $existingIndex = $this->existingOperationAttributeIndex($class, $member);

        $operation = $existingIndex === null
            ? new AddAttribute($target, Operation::class, ['operationId' => $operationId])
            : new SetAttributeArgument($target, $existingIndex, 'operationId', $operationId);

        $file = $this->declaringFile($class, $member);

        if ($file === null) {
            return [];
        }

        return [new Fix(
            file: $file,
            description: sprintf('Set operationId "%s" on %s::%s', $operationId, $class, $member),
            ruleId: $finding->ruleId,
            operation: $operation,
        )];
    }

    /**
     * The flat, source-order position of an existing `#[Operation]` attribute on the method, or null
     * when the method carries none (so the fixer adds one instead of mutating).
     */
    private function existingOperationAttributeIndex(string $class, string $member): ?int
    {
        try {
            $reflector = new ReflectionMethod($class, $member);
        } catch (ReflectionException) {
            return null;
        }

        foreach ($reflector->getAttributes() as $index => $attribute) {
            if (is_a($attribute->getName(), Operation::class, true)) {
                return $index;
            }
        }

        return null;
    }

    private function declaringFile(string $class, string $member): ?string
    {
        try {
            $file = new ReflectionMethod($class, $member)->getFileName();
        } catch (ReflectionException) {
            return null;
        }

        return $file ?: null;
    }
}
