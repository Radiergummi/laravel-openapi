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

use function implode;
use function Radiergummi\OpenApi\is_undefined;

/**
 * Reports documents whose info object is missing a contact and/or license.
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

        if (is_undefined($info) || $info === null) {
            return;
        }

        $missing = [];

        if (is_undefined($info->contact) || $info->contact === null) {
            $missing[] = 'contact';
        }

        if (is_undefined($info->license) || $info->license === null) {
            $missing[] = 'license';
        }

        if ($missing === []) {
            return;
        }

        $missingList = implode(' and ', $missing);

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
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
    public function severity(): Severity
    {
        return Severity::Improvable;
    }

    #[Override]
    public function description(): string
    {
        return 'The document info is missing contact and/or license.';
    }
}
