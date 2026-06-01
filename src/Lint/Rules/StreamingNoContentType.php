<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Attributes\Operation;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use ReflectionAttribute;

use function is_array;
use function sprintf;

/**
 * Reports streaming endpoints that do not declare `text/event-stream` content type.
 *
 * When a controller method is annotated with `#[Operation(streaming: true)]`, its corresponding
 * OpenAPI operation should declare `text/event-stream` as a response content type so that clients
 * know to expect server-sent events.
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
            level: $this->level(),
            message: sprintf(
                'Streaming endpoint %s::%s() does not declare text/event-stream content type in its responses',
                $operation->descriptor->controller?->getName() ?? '(unknown)',
                $operation->descriptor->method->getName(),
            ),
            fixHint: 'The #[Operation(streaming: true)] attribute requires a text/event-stream response content type.',
        );
    }

    /**
     * Check whether an operation's responses declare `text/event-stream` content.
     */
    private function hasEventStreamContentType(OA\Operation $operation): bool
    {
        $responses = $operation->responses;

        if ($responses === Generator::UNDEFINED || !is_array($responses)) {
            return false;
        }

        foreach ($responses as $response) {
            if ($response === Generator::UNDEFINED) {
                continue;
            }

            $content = $response->content;

            if ($content === Generator::UNDEFINED || !is_array($content)) {
                continue;
            }

            foreach ($content as $mediaType) {
                if ($mediaType === Generator::UNDEFINED) {
                    continue;
                }

                if (
                    $mediaType instanceof OA\MediaType
                    && $mediaType->mediaType !== Generator::UNDEFINED
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
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Streaming operation has no content-type: text/event-stream response.';
    }
}
