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

final class MetaUnknownRule implements Rule, ApiRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        $knownRuleIds = $context->index->knownRuleIds;

        foreach ($context->suppressions as $directive) {
            if (in_array($directive->ruleId, $knownRuleIds, true)) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf('Suppression references unknown rule "%s"', $directive->ruleId),
                location: new FindingLocation(file: $directive->file, line: $directive->line),
                fixHint: 'Check spelling or remove the obsolete suppression',
                context: ['unknown_id' => $directive->ruleId],
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'meta.unknown-rule';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return '#[IgnoreLint] references a rule ID not in the registry.';
    }
}
