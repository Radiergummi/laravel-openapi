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
    public string $id = 'tags.no-description';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'Document-level tag has no description.';

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
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf('Tag "%s" has no description', $tagName),
                location: new FindingLocation(jsonPointer: '#/tags/' . $index),
                fixHint: 'Add a description to the tag to improve API documentation.',
            );
        }
    }



}
