<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use Spatie\LaravelData\Data;

/**
 * @OA\Schema(schema="ReplaceableDocblock")
 */
final class ReplaceableDocblockData extends Data
{
    public function __construct(
        /**
         * @OA\Property(property="name", type="string", format="email", description="The contact email.")
         */
        public string $name,
    ) {}
}
