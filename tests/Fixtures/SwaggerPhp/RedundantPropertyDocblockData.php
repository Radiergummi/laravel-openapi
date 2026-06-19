<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use Spatie\LaravelData\Data;

/**
 * @OA\Schema(schema="RedundantPropertyDocblock")
 */
final class RedundantPropertyDocblockData extends Data
{
    public function __construct(
        /**
         * @OA\Property(property="name", type="string")
         */
        public string $name,
    ) {}
}
