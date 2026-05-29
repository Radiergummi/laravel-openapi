<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Core\Support\SchemaFromFormRequest;

/**
 * Fixture FormRequest with a file rule — used to verify multipart detection in
 * {@see SchemaFromFormRequest}.
 */
class FileUploadFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'attachment' => ['required', 'file'],
            'label'      => ['required', 'string', 'max:100'],
        ];
    }
}
