<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Console\GenerateCommand;
use Radiergummi\OpenApi\Http\DocsController;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

/**
 * Drives multi-spec generation. Resets scoped state between specs via
 * {@see Container::forgetScopedInstances()} so per-run state cannot leak.
 *
 * Used by {@see GenerateCommand} and {@see DocsController}.
 *
 * @internal
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
    public function generateOne(
        string $name,
        ?string $environment = null,
        bool $retainInferredView = false,
    ): OA\OpenApi {
        return $this->generateForSpec(
            $this->registry->get($name),
            $environment ?? $this->environment,
            $retainInferredView,
        );
    }

    /**
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function generateForSpec(
        SpecDefinition $spec,
        string $environment,
        bool $retainInferredView = false,
    ): OA\OpenApi {
        return $this->withFreshScopedState(
            function () use ($spec, $environment, $retainInferredView): OA\OpenApi {
                // Flip retention on after the reset, so the fresh scoped store records the inferred
                // view during this run; an ordinary generation leaves it disabled and pays nothing.
                if ($retainInferredView) {
                    $this->container->make(InferenceRetention::class)->enable();
                }

                return $this->container->make(OpenApiGenerator::class)->generate($spec, $environment);
            },
        );
    }

    /**
     * Resets scoped instances, then runs `$generate`. The {@see FindingsCollector} is captured
     * before the reset and restored after; when `$discardFindings` is true a throwaway collector
     * absorbs findings instead.
     *
     * @template T
     *
     * @param callable(): T $generate
     *
     * @return T
     *
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    private function withFreshScopedState(callable $generate, bool $discardFindings = false): mixed
    {
        $collector = $this->container->resolved(FindingsCollector::class)
            ? $this->container->make(FindingsCollector::class)
            : null;

        $this->container->forgetScopedInstances();

        $active = $discardFindings ? new ArrayFindingsCollector() : $collector;

        if ($active !== null) {
            $this->container->instance(FindingsCollector::class, $active);
        }

        try {
            return $generate();
        } finally {
            if ($discardFindings) {
                $this->container->forgetInstance(FindingsCollector::class);
            }

            if ($collector !== null) {
                $this->container->instance(FindingsCollector::class, $collector);
            }
        }
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
