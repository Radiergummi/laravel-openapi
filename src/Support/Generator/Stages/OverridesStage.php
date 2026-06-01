<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;
use Radiergummi\OpenApi\Support\Generator\SpecPipeline;

use function is_array;
use function Radiergummi\OpenApi\is_undefined;
use function str_starts_with;
use function substr;

/**
 * Applies `config('openapi.overrides')` to assembled operations.
 *
 * Runs after every registry-resolved stage (so config overrides beat plugin contributions and
 * convention-derived values) but before the terminal {@see TransformersStage} (so a user's
 * code-based `transformDocument()` callback retains the final word). Wired as a fixed pre-terminal
 * step in {@see SpecPipeline} — always loaded, independent of any plugin. Spec-only: it mutates the
 * emitted document, never the host app.
 *
 * @internal
 */
#[Scoped]
final readonly class OverridesStage implements SpecStage
{
    public function __construct(
        private OverrideMatcher $matcher,
    ) {}

    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        // The default installation configures no overrides — skip the whole document walk.
        if (!is_array($document->paths) || !$this->matcher->hasOverrides()) {
            return;
        }

        foreach ($document->paths as $pathItem) {
            if (!$pathItem instanceof OA\PathItem || is_undefined($pathItem->path)) {
                continue;
            }

            foreach (HttpMethod::cases() as $method) {
                $operation = $pathItem->{$method->value} ?? null;

                if (!$operation instanceof OA\Operation) {
                    continue;
                }

                // The operation is bound to its source route by PathsStage; reuse that binding
                // rather than maintaining a parallel route-name index.
                $routeName = $context->actionFor($operation)?->route->getName();
                $fields = $this->matcher->fieldsFor($routeName, $pathItem->path);

                if ($fields === []) {
                    continue;
                }

                $this->applyFields($operation, $fields);
            }
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function applyFields(OA\Operation $operation, array $fields): void
    {
        foreach ($fields as $field => $value) {
            if (str_starts_with($field, 'x-')) {
                $x = is_undefined($operation->x) ? [] : $operation->x;
                $x[substr($field, 2)] = $value;
                $operation->x = $x;

                continue;
            }

            // Allowlisted fields map 1:1 to OA\Operation properties.
            $operation->{$field} = $value;
        }
    }
}
