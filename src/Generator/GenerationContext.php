<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Generator;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use SplObjectStorage;

/**
 * Per-run inputs shared across every stage in a single {@see SpecPipeline::run()} invocation.
 * The per-operation action lookup is the only mutable state; all other fields are immutable.
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
