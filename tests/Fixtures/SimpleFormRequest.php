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
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Core\Support\SchemaFromFormRequest;

/**
 * Fixture FormRequest with a mix of rules for unit-test coverage of {@see SchemaFromFormRequest}.
 */
class SimpleFormRequest extends FormRequest
{
    #[RequestField(description: 'The target URL.', example: 'https://example.com', format: 'uri')]
    public const string PARAM_URL = 'url';

    public function rules(): array
    {
        return [
            self::PARAM_URL => ['required', 'string', 'url:http,https', 'max:2048'],
            'name'          => ['required', 'string', 'max:100'],
            'count'         => ['sometimes', 'integer', 'min:1', 'max:50'],
            'note'          => ['nullable', 'string'],
        ];
    }
}
