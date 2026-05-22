<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use Illuminate\Container\Container;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use ReflectionException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Drives multi-spec generation in a single process.
 *
 * Per-run state in {@see ComponentSchemaRegistry} and {@see ExampleFileLoader} is reset
 * between specs by calling {@see Container::forgetScopedInstances()} and re-resolving
 * {@see OpenApiGenerator} fresh. This is the same scoped-rebinding pattern
 * {@see \Radiergummi\OpenApi\Core\Lint\LintRunner} uses for its findings collector.
 *
 * Used by {@see \Radiergummi\OpenApi\Console\GenerateCommand} and
 * {@see \Radiergummi\OpenApi\Http\DocsController}.
 */
final readonly class OpenApiGenerationOrchestrator
{
    public function __construct(
        private Container $container,
        private SpecRegistry $registry,
    ) {}

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Symfony\Component\TypeInfo\Exception\UnsupportedException
     * @throws InvalidArgumentException                                   if the named spec is not defined
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function generateOne(string $name, string $environment): OA\OpenApi
    {
        return $this->generateForSpec($this->registry->get($name), $environment);
    }

    /**
     * @return array<string, OA\OpenApi>
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     * @throws \Symfony\Component\TypeInfo\Exception\UnsupportedException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function generateAll(string $environment): array
    {
        $documents = [];

        foreach ($this->registry->all() as $spec) {
            $documents[$spec->name] = $this->generateForSpec($spec, $environment);
        }

        return $documents;
    }

    private function generateForSpec(SpecDefinition $spec, string $environment): OA\OpenApi
    {
        $this->container->forgetScopedInstances();

        return $this->container->make(OpenApiGenerator::class)->generate($spec, $environment);
    }
}
