<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Spatie\LaravelData\Data;

class NoFileUploadFixtureData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
