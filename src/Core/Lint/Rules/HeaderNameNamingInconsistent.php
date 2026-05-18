<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\HeaderRule as HeaderRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\HeaderNode;

use function sprintf;

/**
 * Reports response header names that do not follow the configured naming convention.
 *
 * Note: HTTP header names are case-insensitive per RFC 7230, so this rule is
 * a documentation-consistency house-style check only — it does not affect
 * protocol correctness. The default convention is {@see IdentifierCase::Train}
 * (e.g. `X-Request-Id`), which matches conventional HTTP header style.
 */
final readonly class HeaderNameNamingInconsistent extends AbstractNamingRule implements HeaderRuleVisitor
{
    public function __construct(IdentifierCase $case = IdentifierCase::Train)
    {
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
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Header name "%s" does not follow the %s naming convention',
                $header->name,
                $this->case->label(),
            ),
            fixHint: $this->fixHint('header names'),
        );
    }

    #[Override]
    public function id(): string
    {
        return 'header.name-naming-inconsistent';
    }

    #[Override]
    public function description(): string
    {
        return "Header name doesn't follow the project's header_case convention.";
    }
}
