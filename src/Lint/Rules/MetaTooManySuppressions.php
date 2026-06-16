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

use function sprintf;

/**
 * Reports files with more suppression directives than the configured threshold.
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
