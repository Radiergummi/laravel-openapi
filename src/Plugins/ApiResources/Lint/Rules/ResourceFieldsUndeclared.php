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
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use ReflectionClass;

use function sprintf;

/**
 * Flags an operation whose resource response class declares no `#[ResourceField]` — the response
 * shape is unknown, yielding an empty schema.
 */
final readonly class ResourceFieldsUndeclared implements Rule, OperationRuleVisitor
{
    public function __construct(
        private ResourceTargetLocator $locator,
    ) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook || $operation->descriptor === null) {
            return;
        }

        $target = $this->locator->locate($operation->descriptor);

        if ($target === null || $target->isAmbiguous()) {
            return;
        }

        /** @var class-string $resourceClass */
        $resourceClass = $target->resourceClass;

        if (
            $resourceClass === JsonResource::class
            || new ReflectionClass($resourceClass)->isAbstract()
        ) {
            return;
        }

        if ($context->reflectionCache->classAttributes($resourceClass, ResourceField::class) !== []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s returns %s but it declares no #[ResourceField] — the response schema is empty',
                $operation->method->forDisplay(),
                $operation->pathUri,
                $resourceClass,
            ),
            fixHint: 'Declare each output key with a class-level #[ResourceField] on the resource.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'resource.fields-undeclared';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'An API Resource used as a response declares no #[ResourceField] attributes.';
    }
}
