<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

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

use function is_string;
use function trim;

/**
 * Reports documents whose info.description is missing or empty.
 *
 * The info object's description field gives consumers an overview of the API's purpose, audience,
 * and usage. Leaving it blank results in generated documentation that lacks essential context.
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

        // info itself may be UNDEFINED if the spec is incomplete
        if ($info === Generator::UNDEFINED || $info === null) {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: 'The document info object is missing',
                location: new FindingLocation(jsonPointer: '#/info'),
                fixHint: 'Add an info.description to the OpenAPI document.',
            );

            return;
        }

        $description = $info->description;

        if (
            $description === Generator::UNDEFINED
            || !is_string($description)
            || trim($description) === ''
        ) {
            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
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
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'The document info.description is empty.';
    }
}
