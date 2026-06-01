<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;

use function is_array;
use function str_starts_with;
use function substr;

/**
 * Applies `config('openapi.overrides')` to assembled operations.
 *
 * Runs after every registry-resolved stage (so config overrides beat plugin contributions and
 * convention-derived values) but before the terminal {@see TransformersStage} (so a user's
 * code-based `transformDocument()` callback retains the final word). Wired as a fixed pre-terminal
 * step in {@see \Radiergummi\OpenApi\Support\Generator\SpecPipeline} — always loaded, independent
 * of any plugin. Spec-only: it mutates the emitted document, never the host app.
 *
 * @internal
 */
#[Scoped]
final readonly class OverridesStage implements SpecStage
{
    /** @var list<string> lowercase HTTP verb properties on an {@see OA\PathItem} */
    private const array HTTP_METHODS = [
        'get',
        'post',
        'put',
        'patch',
        'delete',
        'options',
        'head',
        'trace',
    ];

    public function __construct(
        private OverrideMatcher $matcher,
    ) {}

    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        // The default install configures no overrides — skip the whole document walk.
        if (!$this->matcher->hasOverrides() || !is_array($document->paths)) {
            return;
        }

        foreach ($document->paths as $pathItem) {
            if (!$pathItem instanceof OA\PathItem || $pathItem->path === Generator::UNDEFINED) {
                continue;
            }

            foreach (self::HTTP_METHODS as $method) {
                $operation = $pathItem->{$method} ?? null;

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
                $x = $operation->x === Generator::UNDEFINED ? [] : $operation->x;
                $x[substr($field, 2)] = $value;
                $operation->x = $x;

                continue;
            }

            // Allowlisted fields map 1:1 to OA\Operation properties.
            $operation->{$field} = $value;
        }
    }
}
