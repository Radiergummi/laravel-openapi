<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Generator\GenerationContext;

use function config;

/**
 * Writes top-level document fields: openapi version, info, servers (with app.url fallback), tags.
 */
#[Scoped]
final readonly class RootStage implements SpecStage
{
    public function apply(OA\OpenApi $doc, GenerationContext $ctx): void
    {
        $spec = $ctx->spec;

        $doc->openapi = '3.1.0';
        $doc->info = $spec->info;
        $doc->servers = $spec->servers !== [] ? $spec->servers : $this->fallbackServers();

        if ($spec->tags !== []) {
            $doc->tags = $spec->tags;
        }
    }

    /** @return list<OA\Server> */
    private function fallbackServers(): array
    {
        return [new OA\Server(['url' => (string) config('app.url')])];
    }
}
