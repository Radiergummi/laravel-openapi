<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Support\Generator\OverrideMatcher;

use function array_values;
use function is_array;
use function Radiergummi\OpenApi\is_undefined;
use function str_starts_with;
use function substr;

/**
 * Applies `config('openapi.overrides')` to assembled operations.
 *
 * Runs after all plugin stages and before {@see TransformersStage}. Always loaded, plugin-independent.
 *
 * @internal
 */
#[Scoped]
final readonly class OverridesStage implements SpecStage
{
    public function __construct(
        private OverrideMatcher $matcher,
    ) {}

    #[Override]
    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        // Short-circuit: most installations have no overrides configured.
        if (!$this->matcher->hasOverrides) {
            return;
        }

        if (is_array($document->paths)) {
            foreach ($document->paths as $pathItem) {
                if (!$pathItem instanceof OA\PathItem || is_undefined($pathItem->path)) {
                    continue;
                }

                $this->applyToPathItem($pathItem, $pathItem->path, $context);
            }
        }

        if (is_array($document->webhooks)) {
            foreach ($document->webhooks as $webhookItem) {
                if (!$webhookItem instanceof OA\Webhook || is_undefined($webhookItem->webhook)) {
                    continue;
                }

                $this->applyToPathItem($webhookItem, $webhookItem->webhook, $context);
            }
        }
    }

    private function applyToPathItem(OA\PathItem $pathItem, string $lookupKey, GenerationContext $context): void
    {
        foreach (HttpMethod::cases() as $method) {
            $operation = $pathItem->{$method->value} ?? null;

            if (!$operation instanceof OA\Operation) {
                continue;
            }

            // Reuse the binding PathsStage set rather than maintaining a parallel route-name index.
            $routeName = $context->actionFor($operation)?->route->getName();
            $fields = $this->matcher->fieldsFor($routeName, $lookupKey);

            if ($fields === []) {
                continue;
            }

            $this->applyFields($operation, $fields);
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

            // tags must be a list<string>; re-index to satisfy swagger-php's array_is_list check.
            if ($field === 'tags' && is_array($value)) {
                $value = array_values($value);
            }

            $operation->{$field} = $value;
        }
    }
}
