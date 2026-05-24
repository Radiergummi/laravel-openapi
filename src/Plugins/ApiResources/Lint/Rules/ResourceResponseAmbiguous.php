<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\ApiResources\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\ApiResources\ResourceClassLocator;

use function sprintf;

/**
 * Flags an operation that returns a resource collection type without a `#[ResponseResource]`
 * naming the item class — the response shape cannot be derived and the endpoint falls back to a
 * bare `200 OK`.
 */
final readonly class ResourceResponseAmbiguous implements Rule, OperationRuleVisitor
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

        if ($target === null || !$target->isAmbiguous()) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s returns a resource collection but no #[ResponseResource] names the item class',
                $operation->method,
                $operation->pathUri,
            ),
            fixHint: 'Add #[ResponseResource(SomeResource::class, collection: true)] to the action.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'resource.response-ambiguous';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A resource collection response has no #[ResponseResource] naming its item class.';
    }
}
