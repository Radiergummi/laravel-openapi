<?php

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
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use ReflectionException;
use RuntimeException;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use UnexpectedValueException;

use function assert;

/**
 * Assembles the OpenAPI document by running the registered stages against a shared {@see OpenApi}.
 *
 * A pure executor: it resolves every stage from {@see OpenApiRegistry::$stages} and applies it in
 * registration order. The order — pre-plugin stages, plugin stages, then the post-plugin flush and
 * terminal override/transformer stages — is established in one place, the
 * {@see \Radiergummi\OpenApi\OpenApiServiceProvider} registry factory closure; this class holds no
 * stages of its own.
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
            $document = new OpenApi(['openapi' => OpenApi::VERSION_3_1_0]);
            $context = new GenerationContext($spec, $environment);

            foreach ($this->registry->stages as $stageClass) {
                $stage = $this->container->make($stageClass);
                assert($stage instanceof SpecStage);
                $stage->apply($document, $context);
            }

            return $document;
        } finally {
            Generator::$context = $previousContext;
        }
    }
}
