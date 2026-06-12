<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
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
 * subclass — the case {@see ResourceFieldsUndeclared} deliberately skips (there is no concrete
 * class to carry `#[ResourceField]`). The generator emits a `200` with an empty `{data: {}}`
 * envelope, so the endpoint silently ships an undocumented payload.
 *
 * The complement of {@see ResourceFieldsUndeclared} (concrete resource, empty shape) and
 * {@see ResourceResponseAmbiguous} (collection with no item class): together the three cover every
 * way a resource response ends up shapeless. Both this rule and `fields-undeclared` apply the same
 * emptiness gate, so a base/abstract resource that *does* carry an inferable shape (a readable
 * `toArray()` literal or a resolvable wrapped model) is not flagged.
 */
final readonly class ResourceResponseEmpty implements Rule, OperationRuleVisitor
{
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

        // A wrapped-model target documents the model's schema, not an empty envelope.
        if ($resourceClass === null) {
            return;
        }

        // Only the base/abstract case — a concrete resource is `resource.fields-undeclared`'s job.
        if ($resourceClass !== JsonResource::class && !new ReflectionClass($resourceClass)->isAbstract()) {
            return;
        }

        // Same emptiness gate as `fields-undeclared`: a readable toArray() literal with fields, or
        // a resolvable wrapped model, produces a non-empty schema — "the schema is empty" would be
        // untrue. The bare base `JsonResource` has neither, so it falls through to the finding.
        /** @var class-string<JsonResource> $resourceClass */
        $inferred = $this->toArrayReader->read($resourceClass);

        if ($inferred !== null && $inferred->fields !== []) {
            return;
        }

        if ($inferred === null && $this->wrappedModelLocator->locate($resourceClass) !== null) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
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

    #[Override]
    public function id(): string
    {
        return 'resource.response-empty';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A resource response resolves to the base or an abstract JsonResource with no inferable '
            . 'shape — it ships an empty {data: {}} envelope.';
    }
}
