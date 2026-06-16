<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\ErrorContributors;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineValidatorRulesReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionNamedType;

/**
 * Infers a 422 error response from a {@see FormRequest} parameter or a whitelisted inline
 * `validate()` call. A degraded scan (call matched, rules unreadable) still emits the 422:
 * the call itself guarantees the {@see ValidationException}.
 */
#[Scoped]
final readonly class ValidationErrorContributor implements ErrorResponseContributor
{
    /**
     * @param array<string, array{status: int, description: string}> $exceptionMap
     */
    public function __construct(
        private InlineValidatorRulesReader $inlineValidatorRulesReader,
        #[Config('openapi.exception_responses', default: [])]
        private array $exceptionMap = [],
    ) {}

    /**
     * @return list<ErrorDescriptor>
     */
    #[Override]
    public function contribute(ActionDescriptor $descriptor): array
    {
        if (!$this->hasFormRequestParameter($descriptor) && !$this->hasInlineValidateCall($descriptor)) {
            return [];
        }

        $entry = $this->exceptionMap[ValidationException::class] ?? null;

        if ($entry === null) {
            return [];
        }

        return [
            new ErrorDescriptor(
                status: (int) $entry['status'],
                exceptionClass: ValidationException::class,
                description: (string) $entry['description'],
                action: $descriptor,
            ),
        ];
    }

    private function hasFormRequestParameter(ActionDescriptor $descriptor): bool
    {
        if ($descriptor->method === null) {
            return false;
        }

        foreach ($descriptor->method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (class_exists($className) && is_subclass_of($className, FormRequest::class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the method body contains a whitelisted inline `validate()` call shape.
     */
    private function hasInlineValidateCall(ActionDescriptor $descriptor): bool
    {
        if ($descriptor->method === null) {
            return false;
        }

        return $this->inlineValidatorRulesReader->read($descriptor->method) !== null;
    }
}
