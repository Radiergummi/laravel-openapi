<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
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

use function sprintf;

/**
 * Reports when a single file has too many suppression directives, which may indicate that the
 * file needs refactoring rather than more suppressions.
 */
final readonly class MetaTooManySuppressions implements Rule, ApiRuleVisitor
{
    public function __construct(private int $threshold = 5) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($context->suppressions as $directive) {
            $counts[$directive->file] = ($counts[$directive->file] ?? 0) + 1;
        }

        foreach ($counts as $file => $count) {
            if ($count <= $this->threshold) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'File %s has %d suppression directives (threshold: %d) — consider fixing the underlying issues',
                    $file,
                    $count,
                    $this->threshold,
                ),
                location: new FindingLocation(file: $file),
                fixHint: 'Fix the underlying lint issues instead of suppressing them.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'meta.too-many-suppressions';
    }

    #[Override]
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Symbol carries an excessive number of suppression directives.';
    }
}
