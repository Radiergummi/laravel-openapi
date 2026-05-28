<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use OpenApi\Annotations\OpenApi;
use OpenApi\Context;
use OpenApi\Generator;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Support\Generator\Stages\TransformersStage;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function assert;

/**
 * Assembles the OpenAPI document by running ordered stages against a shared {@see OpenApi}.
 *
 * Stages are resolved from {@see OpenApiRegistry::stages()} in registration order — core stages
 * (registered by {@see \Radiergummi\OpenApi\Core\Registration::register()}) come
 * first, plugin stages follow. {@see TransformersStage} runs last as a fixed terminal step so
 * user-registered document transformers see the fully assembled spec.
 *
 * Pins swagger-php's {@see Context} to OpenAPI 3.1 for the duration of the run so nullable
 * type unions (`type: ['…','null']`) serialise as 3.1 unions instead of the 3.0 `nullable: true`
 * keyword. The pin lives here, at the pipeline boundary, so every caller (including unit tests
 * that exercise {@see SpecPipeline} directly) gets the same protection.
 *
 * @internal
 */
#[Scoped]
final readonly class SpecPipeline
{
    public function __construct(
        private OpenApiRegistry $registry,
        private Container $container,
        private TransformersStage $terminalStage,
    ) {}

    /**
     * @throws BindingResolutionException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     * @throws UnsupportedException
     */
    public function run(SpecDefinition $spec, string $environment): OpenApi
    {
        $previousContext = Generator::$context;
        Generator::$context = new Context(['version' => OpenApi::VERSION_3_1_0]);

        try {
            $doc = new OpenApi(['openapi' => '3.1.0']);
            $ctx = new GenerationContext($spec, $environment);

            foreach ($this->registry->stages() as $stageClass) {
                $stage = $this->container->make($stageClass);
                assert($stage instanceof SpecStage);
                $stage->apply($doc, $ctx);
            }

            $this->terminalStage->apply($doc, $ctx);

            return $doc;
        } finally {
            Generator::$context = $previousContext;
        }
    }
}
