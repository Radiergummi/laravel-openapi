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
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\HeaderRule as HeaderRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\HeaderNode;
use Radiergummi\OpenApi\Core\Lint\Tree\ResponseNode;

use function sprintf;
use function trim;

/**
 * Reports response headers that have no description.
 *
 * Headers without descriptions make it harder for API consumers to understand
 * what information each header conveys.
 */
final class HeaderDescriptionMissing implements Rule, HeaderRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkHeader(HeaderNode $header, LintContext $context): iterable
    {
        if ($header->description !== null && trim($header->description) !== '') {
            return;
        }

        $parent = $header->parent();
        $operation = $parent instanceof ResponseNode ? $parent->operation() : null;

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Header "%s" on %s %s has no description',
                $header->name,
                $operation !== null ? $operation->method : '(unknown)',
                $operation !== null ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Add a description to the response header to improve API documentation.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'header.description-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'Response header has no description.';
    }
}
