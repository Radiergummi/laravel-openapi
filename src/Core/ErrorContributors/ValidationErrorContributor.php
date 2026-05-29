<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\ErrorContributors;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Override;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionNamedType;

/**
 * Infers a 422 Validation Failed error response from the presence of a FormRequest parameter.
 *
 * When a controller action declares a {@see FormRequest} subclass as a parameter, Laravel will
 * automatically validate the request and throw a {@see ValidationException} on failure. This
 * contributor detects that pattern and emits the corresponding {@see ErrorDescriptor} based on
 * `config('openapi.exception_responses')[ValidationException::class]`.
 */
#[Scoped]
final readonly class ValidationErrorContributor implements ErrorResponseContributor
{
    /**
     * @param array<string, array{status: int, description: string}> $exceptionMap
     */
    public function __construct(
        #[Config('openapi.exception_responses', default: [])]
        private array $exceptionMap = [],
    ) {}

    /**
     * @return list<ErrorDescriptor>
     */
    #[Override]
    public function contribute(ActionDescriptor $descriptor): array
    {
        if (!$this->hasFormRequestParameter($descriptor)) {
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
}
