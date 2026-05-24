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
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ApiRule as ApiRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;

use function array_keys;
use function array_slice;
use function count;
use function implode;
use function sprintf;
use function str_ends_with;

/**
 * Reports when some paths end with a trailing slash and others don't, indicating an inconsistency
 * in path naming conventions.
 *
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
        // Keyed by path URI so multiple operations on the same path (GET, POST, …) are only
        // counted once.
        /** @var array<string, true> $withSlash */
        $withSlash = [];

        /** @var array<string, true> $withoutSlash */
        $withoutSlash = [];

        foreach ($api->operations as $operation) {
            $pathUri = $operation->pathUri;

            // Skip the root path since it trivially ends with /
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
            level: $this->level(),
            message: sprintf(
                'Inconsistent trailing slashes: %d path(s) end with a slash (e.g. %s) and %d do not (e.g. %s)',
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
    public function level(): int
    {
        return 3;
    }

    #[Override]
    public function description(): string
    {
        return 'Trailing-slash usage is inconsistent across paths.';
    }
}
