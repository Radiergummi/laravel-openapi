<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Plugins\Core\Support\SpecTime\SpecTimeRequest;
use ReflectionException;
use ReflectionMethod;
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
    public function __construct(
        private FormRequestStaticRulesReader $staticRulesReader,
    ) {}

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
            // Invocation threw on runtime state. Fall back to a static read of the rules() array
            // literal before giving up; many rules() bodies hold the base rules as a plain literal.
            $staticRules = $this->readStaticRules($formRequestClass);

            if ($staticRules !== null) {
                return FormRequestRulesResult::recovered($staticRules);
            }

            return FormRequestRulesResult::degraded(
                sprintf('%s (no static rules() literal to fall back to)', $exception->getMessage()),
            );
        }
    }

    /**
     * @param class-string<FormRequest> $formRequestClass
     *
     * @return null|array<string, array<int, mixed>|string>
     */
    private function readStaticRules(string $formRequestClass): ?array
    {
        try {
            return $this->staticRulesReader->read(new ReflectionMethod($formRequestClass, 'rules'));
        } catch (ReflectionException) {
            return null;
        }
    }
}
