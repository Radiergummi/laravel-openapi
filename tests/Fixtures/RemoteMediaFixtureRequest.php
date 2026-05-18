<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture FormRequest used by {@see RemoteMediaFixtureController} to exercise
 * FormRequest-based request-schema extraction (required fields, max length,
 * `sometimes` rules).
 */
class RemoteMediaFixtureRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'url' => [
                'required',
                'string',
                'url:http,https',
                'max:2048',
            ],
            'forwardErrors' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}
