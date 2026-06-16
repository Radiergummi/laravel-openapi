<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Plugins\Core\Support\SchemaFromFormRequest;

/**
 * Fixture FormRequest covering all four file-upload rule forms — used to verify multipart
 * detection and binary-field mapping in {@see SchemaFromFormRequest}.
 */
class FileUploadFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'attachment' => ['required', 'file'],
            'avatar' => ['required', 'image'],
            'document' => ['required', 'mimes:pdf,doc'],
            'banner' => ['required', 'dimensions:min_width=100,min_height=100'],
            'label' => ['required', 'string', 'max:100'],
        ];
    }
}
