<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;

use function explode;
use function implode;
use function sprintf;
use function str_starts_with;

/**
 * Reports URL path segments that do not follow the configured naming convention.
 *
 * Only {@see IdentifierCase::Kebab} and {@see IdentifierCase::Snake} are meaningful for URL path
 * segments; other cases are accepted by the rule but are not recommended for REST APIs.
 *
 * The path URI is split on `/`. Empty segments and `{param}` placeholder segments (those beginning
 * with `{`) are skipped. One finding is emitted per operation listing all offending segments.
 * Default: {@see IdentifierCase::Kebab}.
 */
#[Scoped]
final readonly class PathSegmentNamingInconsistent extends AbstractNamingRule implements OperationRuleVisitor
{
    public function __construct(
        #[Config('openapi.lint.style.path_segment_case', 'kebab')]
        IdentifierCase|string $case = IdentifierCase::Kebab,
    ) {
        parent::__construct($case);
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $offending = [];

        foreach (explode('/', $operation->pathUri) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (str_starts_with($segment, '{')) {
                continue;
            }

            if (!$this->conforms($segment)) {
                $offending[] = $segment;
            }
        }

        if ($offending === []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                'Path segment(s) [%s] on %s %s do not follow the %s naming convention',
                implode(', ', $offending),
                $operation->method,
                $operation->pathUri,
                $this->case->label(),
            ),
            fixHint: $this->fixHint('path segments'),
        );
    }

    #[Override]
    public function id(): string
    {
        return 'path.segment-naming-inconsistent';
    }

    #[Override]
    public function description(): string
    {
        return "URL path segment doesn't follow the project's path_segment_case convention.";
    }
}
