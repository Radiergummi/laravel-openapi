<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\IdentifierCase;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function explode;
use function implode;
use function preg_match;
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

            // File-extension carve-out: peel a short alphanumeric-lowercase tail (e.g. `openapi.yaml`,
            // `feed.atom`) and validate only the head against the configured case. The 8-char cap keeps
            // genuinely-misnamed segments like `foo.SomeMisuse` failing.
            $head = $segment;

            if (preg_match('/^(.+)\.([a-z0-9]{1,8})$/', $segment, $matches) === 1) {
                $head = $matches[1];
            }

            if (!$this->conforms($head)) {
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
                $operation->method->forDisplay(),
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
