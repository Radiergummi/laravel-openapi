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

/**
 * Reports files with more suppression directives than the configured threshold.
 */
final class MetaTooManySuppressions implements Rule, ApiRuleVisitor
{
    public string $id = 'meta.too-many-suppressions';
    public Severity $severity = Severity::Inconsistent;
    public string $description = 'Symbol carries an excessive number of suppression directives.';

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
                ruleId: $this->id,
                severity: $this->severity,
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



}
