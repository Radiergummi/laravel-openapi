<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\InferenceRetention;

use function is_array;
use function is_string;
use function ltrim;
use function Radiergummi\OpenApi\is_defined;
use function strtolower;

/**
 * Inference-only view used by migration rules to compare against authored annotations.
 *
 * Built once per spec by {@see LintRunner} from the retained inference side channel of a single
 * generation; empty unless a rule implements
 * {@see \Radiergummi\OpenApi\Contracts\Lint\NeedsInferenceDocument}.
 *
 * @internal
 */
final readonly class InferenceView
{
    /**
     * @param array<class-string, OA\Schema> $schemasByClass
     * @param array<string, OA\Operation>    $operationsByKey Keyed by {@see operationKey()}.
     * @param array<string, OA\Schema>       $schemasByName   Inferred components keyed by component name.
     */
    public function __construct(
        private array $schemasByClass = [],
        private array $operationsByKey = [],
        private array $schemasByName = [],
    ) {}

    /**
     * Assembles the view from a single generation's retained side channel: the primary document's
     * inference-owned component schemas (harvester-authored-only names excluded) and the pre-merge
     * operations the harvester recorded.
     */
    public static function fromRetention(
        OA\OpenApi $document,
        ComponentSchemaRegistry $registry,
        InferenceRetention $retention,
    ): self {
        $schemasByClass = [];

        foreach ($registry->componentClassMap() as $key => $class) {
            $schema = $registry->schemaForKey($key);

            if ($schema !== null) {
                $schemasByClass[$class] = $schema;
            }
        }

        $schemasByName = [];
        $components = $document->components;

        if ($components instanceof OA\Components && is_array($components->schemas)) {
            foreach ($components->schemas as $schema) {
                if (
                    $schema instanceof OA\Schema
                    && is_defined($schema->schema)
                    && is_string($schema->schema)
                    && !$retention->isAuthoredOnlySchema($schema->schema)
                ) {
                    $schemasByName[$schema->schema] = $schema;
                }
            }
        }

        return new self($schemasByClass, $retention->inferredOperations(), $schemasByName);
    }

    /** Lookup key: lowercased method, space, URI without leading slash. */
    public static function operationKey(string $method, string $uri): string
    {
        return strtolower($method) . ' ' . ltrim($uri, '/');
    }

    /**
     * @param class-string $class
     */
    public function schemaForClass(string $class): ?OA\Schema
    {
        return $this->schemasByClass[ltrim($class, '\\')] ?? null;
    }

    /**
     * The inference-only operation for a route, or null.
     */
    public function operationForRoute(string $method, string $uri): ?OA\Operation
    {
        return $this->operationsByKey[self::operationKey($method, $uri)] ?? null;
    }

    /**
     * The control component schema with the given component name (a `$ref` target), or null.
     */
    public function schemaForName(string $name): ?OA\Schema
    {
        return $this->schemasByName[$name] ?? null;
    }
}
