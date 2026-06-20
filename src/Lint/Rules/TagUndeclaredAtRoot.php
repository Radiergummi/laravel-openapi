<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function in_array;
use function sprintf;

/**
 * Reports operation tags that are not declared in the top-level `tags` array.
 */
final class TagUndeclaredAtRoot implements Rule, ApiRuleVisitor
{
    public string $id = 'tag.undeclared-at-root';
    public Severity $severity = Severity::Inconsistent;
    public string $description = 'Operation uses a tag not declared in the document-level tags array.';

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
                    ruleId: $this->id,
                    severity: $this->severity,
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



}
