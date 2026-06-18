<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use Spatie\LaravelData\Data;

final class ReplaceableDocblockWithProseData extends Data
{
    public function __construct(
        /**
         * This prose must survive the rewrite.
         *
         * @OA\Property(property="name", type="string", format="email")
         */
        public string $name,
    ) {}
}
