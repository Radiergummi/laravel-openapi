<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function sprintf;
use function trim;

/**
 * Reports top-level tags that have no description.
 *
 * Tags without descriptions reduce the usefulness of generated API documentation. Every tag
 * declared in the root `tags` array should include a meaningful description.
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
                level: $this->level(),
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
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'Document-level tag has no description.';
    }
}
