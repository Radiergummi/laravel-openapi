<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Annotations as OA;
use Spatie\LaravelData\Data;

/**
 * A server resource. This prose must survive the fix.
 *
 * @OA\Schema(
 *     schema="RedundantDocblockProse",
 *
 *     @OA\Property(property="name", type="string"),
 * )
 */
final class RedundantDocblockWithProseData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
