<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Attributes\Operation;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use ReflectionAttribute;

use function is_array;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;
use function sprintf;

/**
 * Reports operations annotated with `#[Operation(streaming: true)]` that lack a
 * `text/event-stream` response content type.
 */
final class StreamingNoContentType implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook) {
            return;
        }

        if ($operation->descriptor === null || $operation->descriptor->method === null) {
            return;
        }

        $attributes = $operation->descriptor->method->getAttributes(
            Operation::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        if ($attributes === []) {
            return;
        }

        $attribute = $attributes[0]->newInstance();

        if (!$attribute->streaming) {
            return;
        }

        if ($this->hasEventStreamContentType($operation->raw)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            severity: $this->severity(),
            message: sprintf(
                'Streaming endpoint %s::%s() does not declare text/event-stream content type in its responses',
                $operation->descriptor->controller?->getName() ?? '(unknown)',
                $operation->descriptor->method->getName(),
            ),
            fixHint: 'The #[Operation(streaming: true)] attribute requires a text/event-stream response content type.',
        );
    }

    private function hasEventStreamContentType(OA\Operation $operation): bool
    {
        $responses = $operation->responses;

        if (is_undefined($responses) || !is_array($responses)) {
            return false;
        }

        foreach ($responses as $response) {
            if (is_undefined($response)) {
                continue;
            }

            $content = $response->content;

            if (is_undefined($content) || !is_array($content)) {
                continue;
            }

            foreach ($content as $mediaType) {
                if (is_undefined($mediaType)) {
                    continue;
                }

                if (
                    $mediaType instanceof OA\MediaType
                    && is_defined($mediaType->mediaType)
                    && $mediaType->mediaType === 'text/event-stream'
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    #[Override]
    public function id(): string
    {
        return 'streaming.no-content-type';
    }

    #[Override]
    public function severity(): Severity
    {
        return Severity::Degraded;
    }

    #[Override]
    public function description(): string
    {
        return 'Streaming operation has no content-type: text/event-stream response.';
    }
}
