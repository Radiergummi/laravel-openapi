<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Generator;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use SplObjectStorage;

/**
 * Per-run inputs shared across every stage in a single {@see SpecPipeline::run()} invocation.
 *
 * The `$spec` and `$environment` fields are immutable per run. The per-operation
 * action lookup populated by PathsStage and read by later stages is the only mutable
 * piece of state on the context.
 */
final class GenerationContext
{
    /** @var SplObjectStorage<OA\Operation, ActionDescriptor> */
    private SplObjectStorage $actions;

    public function __construct(
        public readonly SpecDefinition $spec,
        public readonly string $environment,
    ) {
        $this->actions = new SplObjectStorage();
    }

    public function bindAction(OA\Operation $operation, ActionDescriptor $descriptor): void
    {
        $this->actions[$operation] = $descriptor;
    }

    public function actionFor(OA\Operation $operation): ?ActionDescriptor
    {
        /** @var null|ActionDescriptor */
        return $this->actions[$operation] ?? null;
    }
}
