<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;

use function array_key_exists;

/**
 * Per-run side channel that retains the inference-only view of a spec alongside the authored winner,
 * so the swagger-php migration rules can compare authored annotations against pure inference off a
 * single generation instead of a second, harvester-excluded pass.
 *
 * Populated only when {@see enable()} has been called (the lint layer flips it on when an active rule
 * needs the inferred view); an ordinary `openapi:generate` never touches it, so it stays empty and
 * costs nothing.
 *
 * @internal
 */
#[Scoped]
final class InferenceRetention
{
    private bool $enabled = false;

    /**
     * Inferred schemas a rival producer would have built for an already-owned component, keyed by
     * component key. The winner stays in {@see ComponentSchemaRegistry}; this is the losing view.
     *
     * @var array<string, OA\Schema>
     */
    private array $inferredSchemas = [];

    /**
     * Pre-merge inferred operations, keyed as {@see \Radiergummi\OpenApi\Lint\InferenceView} keys them
     * (`method␠uri`). Recorded before the harvester merges authored responses onto them.
     *
     * @var array<string, OA\Operation>
     */
    private array $inferredOperations = [];

    /**
     * Component schema names contributed only by the harvester (no inference counterpart), so the
     * retained view can exclude them the way the harvester-excluded generation did.
     *
     * @var array<string, true>
     */
    private array $authoredOnlySchemaNames = [];

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function retainInferredSchema(string $key, OA\Schema $schema): void
    {
        $this->inferredSchemas[$key] = $schema;
    }

    public function hasInferredSchema(string $key): bool
    {
        return array_key_exists($key, $this->inferredSchemas);
    }

    /**
     * @return array<string, OA\Schema>
     */
    public function inferredSchemas(): array
    {
        return $this->inferredSchemas;
    }

    public function retainInferredOperation(string $key, OA\Operation $operation): void
    {
        $this->inferredOperations[$key] = $operation;
    }

    /**
     * @return array<string, OA\Operation>
     */
    public function inferredOperations(): array
    {
        return $this->inferredOperations;
    }

    public function markAuthoredOnlySchema(string $name): void
    {
        $this->authoredOnlySchemaNames[$name] = true;
    }

    public function isAuthoredOnlySchema(string $name): bool
    {
        return array_key_exists($name, $this->authoredOnlySchemaNames);
    }
}
