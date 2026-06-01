<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\IgnoreLint;

use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Attributes\IgnoreLint;

#[IgnoreLint('field.name-naming-inconsistent', reason: 'wire format requires snake_case')]
final class SnakeCasedFormRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'error_description' => ['required', 'string'],
            'error_uri' => ['nullable', 'string'],
        ];
    }
}
