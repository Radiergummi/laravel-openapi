<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Console\GenerateCommand;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\LintRunner;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use Radiergummi\OpenApi\Http\DocsController;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

/**
 * Drives multi-spec generation in a single process.
 *
 * Per-run state in {@see ComponentSchemaRegistry} and {@see ExampleFileLoader} is reset between
 * specs by calling {@see Container::forgetScopedInstances()} and re-resolving
 * {@see OpenApiGenerator} fresh. This is the same scoped-rebinding pattern {@see LintRunner} uses
 * for its findings collector.
 *
 * Used by {@see GenerateCommand} and {@see DocsController}.
 */
#[Scoped]
final readonly class OpenApiGenerationOrchestrator
{
    public function __construct(
        private Container $container,
        private SpecRegistry $registry,
        #[Config('app.env', 'production')]
        private string $environment = 'production',
    ) {}

    /**
     * @throws BindingResolutionException
     * @throws InvalidArgumentException   if the named spec is not defined
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function generateOne(string $name, ?string $environment = null): OA\OpenApi
    {
        return $this->generateForSpec(
            $this->registry->get($name),
            $environment ?? $this->environment,
        );
    }

    /**
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function generateForSpec(SpecDefinition $spec, string $environment): OA\OpenApi
    {
        // forgetScopedInstances() wipes every scoped binding including any explicit
        // FindingsCollector override the caller installed (e.g. LintRunner pins an
        // ArrayFindingsCollector to capture extractor-emitted findings). Preserve and restore it so
        // callers don't silently lose findings emitted during generation.
        $collector = $this->container->resolved(FindingsCollector::class)
            ? $this->container->make(FindingsCollector::class)
            : null;

        $this->container->forgetScopedInstances();

        if ($collector !== null) {
            $this->container->instance(FindingsCollector::class, $collector);
        }

        return $this->container->make(OpenApiGenerator::class)->generate(
            $spec,
            $environment,
        );
    }

    /**
     * @return array<string, OA\OpenApi>
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function generateAll(?string $environment = null): array
    {
        $documents = [];

        foreach ($this->registry->all() as $spec) {
            $documents[$spec->name] = $this->generateForSpec(
                $spec,
                $environment ?? $this->environment,
            );
        }

        return $documents;
    }
}
