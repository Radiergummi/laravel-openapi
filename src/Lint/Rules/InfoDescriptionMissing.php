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

use function is_string;
use function Radiergummi\OpenApi\is_undefined;
use function trim;

/**
 * Reports documents whose info.description is missing or empty.
 */
final class InfoDescriptionMissing implements Rule, ApiRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkApi(ApiNode $api, LintContext $context): iterable
    {
        $info = $context->rawSpec->info;

        // UNDEFINED when spec is incomplete, not merely null
        if (is_undefined($info) || $info === null) {
            yield new Finding(
                ruleId: $this->id(),
                severity: $this->severity(),
                message: 'The document info object is missing',
                location: new FindingLocation(jsonPointer: '#/info'),
                fixHint: 'Add an info.description to the OpenAPI document.',
            );

            return;
        }

        $description = $info->description;

        if (
            is_undefined($description)
            || !is_string($description)
            || trim($description) === ''
        ) {
            yield new Finding(
                ruleId: $this->id(),
                severity: $this->severity(),
                message: 'The document info.description is empty',
                location: new FindingLocation(jsonPointer: '#/info/description'),
                fixHint: 'Add a description to info explaining the API purpose and intended audience.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'info.description-missing';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Underspecified;
    }

    #[Override]
    public function description(): string
    {
        return 'The document info.description is empty.';
    }
}
