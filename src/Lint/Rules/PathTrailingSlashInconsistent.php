<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function array_keys;
use function array_slice;
use function count;
use function implode;
use function sprintf;
use function str_ends_with;

/**
 * The root path `/` is excluded from the check since it trivially ends with a slash.
 */
final class PathTrailingSlashInconsistent implements Rule, ApiRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        // Keyed by URI so multiple operations on the same path are counted once.
        /** @var array<string, true> $withSlash */
        $withSlash = [];

        /** @var array<string, true> $withoutSlash */
        $withoutSlash = [];

        foreach ($api->operations as $operation) {
            $pathUri = $operation->pathUri;

            if ($pathUri === '/') {
                continue;
            }

            if (str_ends_with($pathUri, '/')) {
                $withSlash[$pathUri] = true;
            } else {
                $withoutSlash[$pathUri] = true;
            }
        }

        if ($withSlash === [] || $withoutSlash === []) {
            return;
        }

        $withSlash = array_keys($withSlash);
        $withoutSlash = array_keys($withoutSlash);

        $withSlashExamples = implode(', ', array_slice($withSlash, 0, 3));
        $withoutSlashExamples = implode(', ', array_slice($withoutSlash, 0, 3));

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf(
                'Inconsistent trailing slashes: %d path(s) end with a slash (e.g., %s) and %d do not (e.g., %s)',
                count($withSlash),
                $withSlashExamples,
                count($withoutSlash),
                $withoutSlashExamples,
            ),
            fixHint: 'Ensure all paths consistently either use or omit trailing slashes.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'path.trailing-slash-inconsistent';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Inconsistent;
    }

    #[Override]
    public function description(): string
    {
        return 'Trailing-slash usage is inconsistent across paths.';
    }
}
