<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Generator;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Events\SpecGenerationCompleted;
use Radiergummi\OpenApi\Core\Events\SpecGenerationStarted;
use Radiergummi\OpenApi\Core\Generator\Pipeline\SpecPipeline;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function hrtime;

/**
 * Generates an OpenAPI 3.1 document from the application's route definitions.
 *
 * Thin wrapper around {@see SpecPipeline} that dispatches lifecycle events with timing.
 * The swagger-php 3.1 context pin lives inside {@see SpecPipeline::run()}.
 */
#[Scoped]
final readonly class OpenApiGenerator
{
    public function __construct(
        private SpecPipeline $pipeline,
        private Dispatcher $events,
    ) {}

    /**
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function generate(SpecDefinition $spec, string $environment): OA\OpenApi
    {
        $this->events->dispatch(new SpecGenerationStarted($spec->name, $environment));
        $startedAtNs = hrtime(true);

        $document = $this->pipeline->run($spec, $environment);

        $this->events->dispatch(new SpecGenerationCompleted(
            spec: $spec->name,
            environment: $environment,
            document: $document,
            durationMs: (hrtime(true) - $startedAtNs) / 1_000_000,
        ));

        return $document;
    }
}
