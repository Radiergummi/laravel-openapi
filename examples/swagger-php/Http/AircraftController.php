<?php

declare(strict_types=1);

namespace Examples\SwaggerPhp\Http;

use Examples\SwaggerPhp\Models\Aircraft;
use Radiergummi\OpenApi\Attributes\Tag;

#[Tag('SwaggerPhp')]
final class AircraftController
{
    /**
     * Show an aircraft.
     *
     * The library infers the operation from the route and PHPDoc; the harvester supplies the
     * response body from the `#[OA\Schema]` attribute on the returned Aircraft model.
     */
    public function show(string $aircraft): Aircraft
    {
        return new Aircraft();
    }
}
