<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ApiRule as ApiRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;
use Override;

use function sprintf;

/**
 * Reports root-level tag names that do not follow the configured naming convention.
 *
 * Tags are typically derived from namespace segments (e.g. `Projects`, `Users`),
 * so the default convention is {@see IdentifierCase::Pascal}.
 */
final readonly class TagNameNamingInconsistent extends AbstractNamingRule implements ApiRuleVisitor
{
    public function __construct(IdentifierCase $case = IdentifierCase::Pascal)
    {
        parent::__construct($case);
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        foreach ($api->declaredTags as $index => $tagName) {
            if ($this->conforms($tagName)) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Tag name "%s" does not follow the %s naming convention',
                    $tagName,
                    $this->case->label(),
                ),
                location: new FindingLocation(jsonPointer: '#/tags/' . $index),
                fixHint: $this->fixHint('tag names'),
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'tag.name-naming-inconsistent';
    }

    #[Override]
    public function description(): string
    {
        return "Tag name doesn't follow the project's tag_case convention.";
    }
}
