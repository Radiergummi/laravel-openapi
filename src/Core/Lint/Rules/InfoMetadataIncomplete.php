<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\ApiRule as ApiRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\ApiNode;

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

        if ($info === Generator::UNDEFINED || $info === null) {
            return;
        }

        $missing = [];

        if ($info->contact === Generator::UNDEFINED || $info->contact === null) {
            $missing[] = 'contact';
        }

        if ($info->license === Generator::UNDEFINED || $info->license === null) {
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
