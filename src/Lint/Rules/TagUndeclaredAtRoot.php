<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function in_array;
use function sprintf;

/**
 * Reports operation tags that are not declared in the top-level `tags` array.
 *
 * The OpenAPI specification recommends declaring all tags at the root level with descriptions.
 * This rule ensures every tag used by an operation has a corresponding entry in the top-level
 * `tags` array.
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
                        $operation->method->forDisplay(),
                        $operation->pathUri,
                    ),
                    location: new FindingLocation(
                        routeMethod: $operation->method,
                        routeUri: $operation->pathUri,
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
