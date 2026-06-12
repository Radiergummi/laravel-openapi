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
 * Infers a 422 Validation Failed error response from the presence of a FormRequest parameter
 * or a whitelisted inline validate() call in the method body.
 *
 * When a controller action declares a {@see FormRequest} subclass as a parameter, Laravel will
 * automatically validate the request and throw a {@see ValidationException} on failure. The same
 * exception is thrown by an inline `$request->validate([...])` (and the other call shapes the
 * Tier-1 body scan matches — see {@see InlineValidatorRulesReader}), so both patterns emit the
 * corresponding {@see ErrorDescriptor} based on
 * `config('openapi.exception_responses')[ValidationException::class]`. A *degraded* scan (the
 * call matched but its rules could not be read) still counts: the 422 is a fact of the call
 * itself, independent of whether the rules were recoverable. The body scan only runs when no
 * FormRequest parameter already answered the question.
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
     * Whether the method body contains one of the whitelisted inline validate() call shapes.
     * Any match counts — recovered or degraded — since the call throws on failure either way.
     */
    private function hasInlineValidateCall(ActionDescriptor $descriptor): bool
    {
        if ($descriptor->method === null) {
            return false;
        }

        return $this->inlineValidatorRulesReader->read($descriptor->method) !== null;
    }
}
