<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Annotations as OA;
use Spatie\LaravelData\Data;

/**
 * @OA\Schema
 */
final class RedundantBareSchemaDocblockData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
