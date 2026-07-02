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

use function assert;

/**
 * Runs the registered stages against a shared {@see OpenApi} document, in registration order.
 *
 * A pure executor that holds no stages of its own; the pipeline order lives in
 * {@see BaselineRegistration::assemble()}.
 *
 * @internal
 */
#[Scoped]
final readonly class SpecPipeline
{
    public const string VERSION = OpenApi::VERSION_3_1_0;

    public function __construct(
        private OpenApiRegistry $registry,
        private Container $container,
    ) {}

    /**
     * Runs the pipeline against the given spec.
     *
     * @throws BindingResolutionException
     */
    public function run(SpecDefinition $spec, string $environment): OpenApi
    {
        // Pin swagger-php's global Context to 3.1 so nullable unions serialize as
        // `type: ['…','null']` rather than the 3.0 `nullable: true`; restored in finally.
        $previousContext = Generator::$context;
        Generator::$context = new Context(['version' => self::VERSION]);

        try {
            $document = new OpenApi(['openapi' => self::VERSION]);
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
