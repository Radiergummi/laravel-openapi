<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Annotations as OA;
use Spatie\LaravelData\Data;

/**
 * @OA\Schema(
 *     schema="RedundantDocblock",
 *
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="count", type="integer"),
 * )
 */
final class RedundantDocblockData extends Data
{
    public function __construct(
        public string $name,
        public int $count,
    ) {}
}
