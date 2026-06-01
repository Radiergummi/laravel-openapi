<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;
use Radiergummi\OpenApi\Lint\Visitors\ApiRule as ApiRuleVisitor;

use function implode;

/**
 * Reports documents whose info object is missing a contact and/or license.
 *
 * Including contact and license information makes it clear who owns the API and under what terms
 * it may be used — important for both internal and external consumers.
 */
final class InfoMetadataIncomplete implements Rule, ApiRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        $info = $context->rawSpec->info;

        if (Generator::isDefault($info) || $info === null) {
            return;
        }

        $missing = [];

        if (Generator::isDefault($info->contact) || $info->contact === null) {
            $missing[] = 'contact';
        }

        if (Generator::isDefault($info->license) || $info->license === null) {
            $missing[] = 'license';
        }

        if ($missing === []) {
            return;
        }

        $missingList = implode(' and ', $missing);

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: "The document info is missing {$missingList}",
            location: new FindingLocation(jsonPointer: '#/info'),
            fixHint: 'Add the missing ' . $missingList . ' field(s) to the info object.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'info.metadata-incomplete';
    }

    #[Override]
    public function level(): int
    {
        return 4;
    }

    #[Override]
    public function description(): string
    {
        return 'The document info is missing contact and/or license.';
    }
}
