<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\HeaderNode;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Visitors\HeaderRule as HeaderRuleVisitor;

use function sprintf;
use function trim;

/**
 * Reports response headers that have no description.
 */
final class HeaderDescriptionMissing implements Rule, HeaderRuleVisitor
{
    public string $id = 'header.description-missing';
    public Severity $severity = Severity::Underspecified;
    public string $description = 'Response header has no description.';

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
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Header "%s" on %s %s has no description',
                $header->name,
                $operation !== null ? $operation->method->forDisplay() : '(unknown)',
                $operation !== null ? $operation->pathUri : '(unknown)',
            ),
            fixHint: 'Add a description to the response header to improve API documentation.',
        );
    }



}
