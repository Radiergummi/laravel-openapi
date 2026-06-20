<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceToArrayReader;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\WrappedModelLocator;
use ReflectionClass;
use ReflectionException;

use function sprintf;

/**
 * Flags an operation whose resource response class declares no `#[ResourceField]` and whose
 * shape the generator cannot infer either (no readable `toArray()` literal, no wrapped model),
 * so the response schema is genuinely empty.
 */
final class ResourceFieldsUndeclared implements Rule, OperationRuleVisitor
{
    public string $id = 'resource.fields-undeclared';
    public Severity $severity = Severity::Degraded;
    public string $description = 'An API Resource used as a response declares no #[ResourceField] attributes '
        . 'and its shape cannot be inferred from toArray() or a wrapped model.';

    public function __construct(
        private ResourceTargetLocator $locator,
        private ResourceToArrayReader $toArrayReader,
        private WrappedModelLocator $wrappedModelLocator,
    ) {}

    /**
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook || $operation->descriptor === null) {
            return;
        }

        $target = $this->locator->locate($operation->descriptor);

        if ($target === null || $target->isAmbiguous) {
            return;
        }

        $resourceClass = $target->resourceClass;

        // A wrapped-model target documents the model's schema; there is no resource to inspect.
        if ($resourceClass === null) {
            return;
        }

        if (
            $resourceClass === JsonResource::class
            || new ReflectionClass($resourceClass)->isAbstract()
        ) {
            return;
        }

        if ($context->reflectionCache->classAttributes($resourceClass, ResourceField::class) !== []) {
            return;
        }

        // Either inference path produces a non-empty schema; rule would be a false positive.
        /** @var class-string<JsonResource> $resourceClass */
        $inferred = $this->toArrayReader->read($resourceClass);

        if ($inferred !== null && $inferred->fields !== []) {
            return;
        }

        if ($inferred === null && $this->wrappedModelLocator->locate($resourceClass) !== null) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                '%s %s returns %s but it declares no #[ResourceField] — the response schema is empty',
                $operation->method->forDisplay(),
                $operation->pathUri,
                $resourceClass,
            ),
            fixHint: 'Declare each output key with a class-level #[ResourceField] on the resource, '
            . 'or add an @mixin model annotation the generator can resolve fields against.',
        );
    }



}
