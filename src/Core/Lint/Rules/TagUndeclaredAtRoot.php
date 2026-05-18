<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ApiRule as ApiRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;

use function in_array;
use function sprintf;

/**
 * Reports operation tags that are not declared in the top-level `tags` array.
 *
 * The OpenAPI specification recommends declaring all tags at the root level
 * with descriptions. This rule ensures every tag used by an operation has a
 * corresponding entry in the top-level `tags` array.
 */
final class TagUndeclaredAtRoot implements Rule, ApiRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        foreach ($api->operations as $operation) {
            foreach ($operation->tags as $tag) {
                if (in_array($tag, $api->declaredTags, true)) {
                    continue;
                }

                yield new Finding(
                    ruleId: $this->id(),
                    level: $this->level(),
                    message: sprintf(
                        'Tag "%s" used on %s %s is not declared in the top-level tags array',
                        $tag,
                        $operation->method,
                        $operation->pathUri,
                    ),
                    location: new FindingLocation(
                        routeUri: $operation->pathUri,
                        routeMethod: $operation->method,
                    ),
                    fixHint: 'Add the tag to the top-level tags array with a description, or use #[Tag] on a controller.',
                );
            }
        }
    }

    #[Override]
    public function id(): string
    {
        return 'tag.undeclared-at-root';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Operation uses a tag not declared in the document-level tags array.';
    }
}
