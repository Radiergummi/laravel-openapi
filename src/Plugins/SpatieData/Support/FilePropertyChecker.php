<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Support;

use Illuminate\Http\UploadedFile;
use ReflectionException;
use Spatie\LaravelData\Data;

/**
 * Detects whether a Data class (or any nested Data class it references) has properties typed as
 * {@see UploadedFile}.
 *
 * Extracted as an interface so {@see Lint\Rules\MultipartFileWithoutMultipart} can be tested
 * without constructing the full {@see SchemaFromDataClass} dependency graph.
 */
interface FilePropertyChecker
{
    /**
     * @param class-string<Data> $dataClass
     *
     * @throws ReflectionException
     */
    public function hasFileProperties(string $dataClass): bool;
}
