<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Data;

class FileUploadFixtureData extends Data
{
    public function __construct(
        public string $name,
        public UploadedFile $file,
    ) {}
}
