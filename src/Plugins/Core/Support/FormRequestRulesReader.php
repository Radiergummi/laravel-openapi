<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Plugins\Core\Support\SpecTime\SpecTimeRequest;
use Throwable;

use function method_exists;
use function sprintf;

/**
 * Reads a {@see FormRequest}'s `rules()` at spec-time and returns the raw rule array.
 *
 * Symmetric with {@see InlineValidatorRulesReader}: it does not map the rules to a schema. Both the
 * request-body builder ({@see SchemaFromFormRequest}) and the query-parameter source share this read
 * so the instantiate + `configure()` + `rules()` + try/catch logic lives in one place; each consumer
 * runs the result through {@see \Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema} itself.
 *
 * @internal
 */
#[Scoped]
final readonly class FormRequestRulesReader
{
    /**
     * @param class-string<FormRequest> $formRequestClass
     */
    public function read(string $formRequestClass): FormRequestRulesResult
    {
        if (!method_exists($formRequestClass, 'rules')) {
            return FormRequestRulesResult::degraded(
                sprintf('%s does not declare a rules() method', $formRequestClass),
            );
        }

        try {
            // configure() wires a permissive route + user resolver so rules() can call
            // $this->route('foo') / $this->user() without throwing.
            $args = SpecTimeRequest::resolveConstructorDeps($formRequestClass);
            $instance = new $formRequestClass(...$args);
            SpecTimeRequest::configure($instance);

            return FormRequestRulesResult::recovered($instance->rules());
        } catch (Throwable $exception) {
            return FormRequestRulesResult::degraded($exception->getMessage());
        }
    }
}
