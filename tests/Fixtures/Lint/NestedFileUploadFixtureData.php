<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Data;

/**
 * A Data class whose UploadedFile property lives one level deep inside a nested Data class —
 * used to verify that MultipartFileWithoutMultipart detects file properties recursively.
 */
class NestedFileUploadFixtureData extends Data
{
    public function __construct(
        public string $title,
        public FileUploadFixtureData $attachment,
    ) {}
}
