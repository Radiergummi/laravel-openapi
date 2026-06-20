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
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceToArrayReader;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\WrappedModelLocator;
use ReflectionClass;
use ReflectionException;

use function sprintf;

/**
 * Flags an operation whose resource response resolves to the base `JsonResource` or an abstract
 * subclass, resulting in an empty `{data: {}}` envelope. Complements {@see ResourceFieldsUndeclared}
 * (concrete resource, empty shape) and {@see ResourceResponseAmbiguous} (collection, no item class).
 * Skips when the shape is actually inferable from `toArray()` or a wrapped model.
 */
final class ResourceResponseEmpty implements Rule, OperationRuleVisitor
{
    public string $id = 'resource.response-empty';
    public Severity $severity = Severity::Degraded;
    public string $description = 'A resource response resolves to the base or an abstract JsonResource with no inferable '
        . 'shape. It ships an empty {data: {}} envelope.';

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

        // Wrapped-model targets document the model's schema, not an empty envelope.
        if ($resourceClass === null) {
            return;
        }

        // Concrete resources are `resource.fields-undeclared`'s job.
        if ($resourceClass !== JsonResource::class && !new ReflectionClass($resourceClass)->isAbstract()) {
            return;
        }

        // Same emptiness gate as `fields-undeclared`: skip when the shape is inferable.
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
                '%s %s resolves to %s — the response schema is an empty {data: {}} envelope',
                $operation->method->forDisplay(),
                $operation->pathUri,
                $resourceClass,
            ),
            fixHint: 'Type the return to a concrete JsonResource (with #[ResourceField]s or an @mixin model), '
            . 'or add #[ResponseResource(SomeResource::class)] to the action.',
        );
    }



}
