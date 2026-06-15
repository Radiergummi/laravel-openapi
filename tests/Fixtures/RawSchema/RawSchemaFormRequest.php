<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\RawSchema;

use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Attributes\RawSchema;

/**
 * A FormRequest whose body is replaced by a literal `#[RawSchema]`. The `email` rule the
 * convention would otherwise map is absent from the literal body, proving the rules() read is
 * skipped.
 */
#[RawSchema([
    'type' => 'object',
    'required' => ['token'],
    'properties' => [
        'token' => ['type' => 'string'],
    ],
])]
class RawSchemaFormRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
