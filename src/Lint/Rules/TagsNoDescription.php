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

use function sprintf;
use function trim;

/**
 * Reports top-level tags declared in the root `tags` array without a description.
 */
final class TagsNoDescription implements Rule, ApiRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        foreach ($api->declaredTags as $index => $tagName) {
            $description = $api->tagDescriptions[$tagName] ?? '';

            if (trim($description) !== '') {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                severity: $this->severity(),
                message: sprintf('Tag "%s" has no description', $tagName),
                location: new FindingLocation(jsonPointer: '#/tags/' . $index),
                fixHint: 'Add a description to the tag to improve API documentation.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'tags.no-description';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Underspecified;
    }

    #[Override]
    public function description(): string
    {
        return 'Document-level tag has no description.';
    }
}
