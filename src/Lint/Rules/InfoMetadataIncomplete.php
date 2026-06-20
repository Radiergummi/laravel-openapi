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
    public string $id = 'info.metadata-incomplete';
    public Severity $severity = Severity::Improvable;
    public string $description = 'The document info is missing contact and/or license.';

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
            ruleId: $this->id,
            severity: $this->severity,
            message: "The document info is missing {$missingList}",
            location: new FindingLocation(jsonPointer: '#/info'),
            fixHint: 'Add the missing ' . $missingList . ' field(s) to the info object.',
        );
    }



}
