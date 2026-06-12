<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Generator\GenerationContext;

use function config;

/**
 * Writes top-level document fields: openapi version, info, servers (with app.url fallback), tags.
 *
 * @internal
 */
#[Scoped]
final readonly class RootStage implements SpecStage
{
    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        $spec = $context->spec;

        $document->openapi = '3.1.0';
        $document->info = $spec->info;
        $document->servers = $spec->servers !== [] ? $spec->servers : $this->fallbackServers();

        if ($spec->tags !== []) {
            $document->tags = $spec->tags;
        }
    }

    /** @return list<OA\Server> */
    private function fallbackServers(): array
    {
        return [new OA\Server(['url' => (string) config('app.url')])];
    }
}
