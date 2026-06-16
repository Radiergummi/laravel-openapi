<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\Fix\MemberKind;
use Radiergummi\OpenApi\Lint\Fix\RemoveAttributeFixer;
use Radiergummi\OpenApi\Lint\Fix\RemoveMode;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\ResponseNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function array_count_values;
use function array_map;
use function sprintf;

final class ResponseDuplicateStatus implements FixableRule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->responses === []) {
            return;
        }

        $statusCodes = array_map(
            static fn(ResponseNode $response): string => (string) $response->statusCode,
            $operation->responses,
        );

        $counts = array_count_values($statusCodes);

        foreach ($counts as $statusCode => $count) {
            if ($count <= 1) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                severity: $this->severity(),
                message: sprintf(
                    'HTTP status %s is declared %d times on %s %s',
                    $statusCode,
                    $count,
                    $operation->method->forDisplay(),
                    $operation->pathUri,
                ),
                fixHint: 'Remove the duplicate #[Response] attribute or change the status code.',
                context: RemoveAttributeFixer::contextForOperation($operation->descriptor, (string) $statusCode),
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'response.duplicate-status';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Broken;
    }

    #[Override]
    public function fixer(): Fixer
    {
        return new RemoveAttributeFixer(
            attribute: Response::class,
            member: MemberKind::Method,
            mode: RemoveMode::Dedupe,
            discriminator: static fn(object $attr): ?string
                => $attr instanceof Response
                ? (string) $attr->status
                : null,
        );
    }

    #[Override]
    public function description(): string
    {
        return 'Two responses on the same operation share the same status code.';
    }
}
