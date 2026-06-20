<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\HeaderNode;
use Radiergummi\OpenApi\Lint\Visitors\HeaderRule as HeaderRuleVisitor;

use function sprintf;

/**
 * Reports response header names that do not follow the configured naming convention.
 * HTTP header names are case-insensitive (RFC 7230), so this is a documentation consistency check.
 */
#[Scoped]
final class HeaderNameNamingInconsistent extends AbstractNamingRule implements HeaderRuleVisitor
{
    public string $id = 'header.name-naming-inconsistent';
    public string $description = "Header name doesn't follow the project's header_case convention.";

    public function __construct(
        #[Config('openapi.lint.style.header_case', 'train')]
        IdentifierCase|string $case = IdentifierCase::Train,
    ) {
        parent::__construct($case);
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkHeader(HeaderNode $header, LintContext $context): iterable
    {
        if ($this->conforms($header->name)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Header name "%s" does not follow the %s naming convention',
                $header->name,
                $this->case->label(),
            ),
            fixHint: $this->fixHint('header names'),
        );
    }


}
