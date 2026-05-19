<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;
use ReflectionClass;

use function sprintf;

/**
 * Flags an operation whose resource response class declares no
 * `#[ResourceField]` — the response shape is unknown, yielding an empty schema.
 */
final readonly class ResourceFieldsUndeclared implements Rule, OperationRule
{
    public function __construct(
        private ResourceClassLocator $locator,
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

        if ((new ReflectionClass($resourceClass))->getAttributes(ResourceField::class) !== []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s returns %s but it declares no #[ResourceField] — the response schema is empty',
                $operation->method,
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
