<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture FormRequest whose nullable fields carry no rule that implies a JSON Schema type, so the
 * type stays unknown while constraints do not.
 */
class UntypedNullableFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bio' => ['nullable', 'min:1'],
            'slug' => ['nullable', 'regex:/^[a-z]+$/'],
            'anything' => ['nullable'],
        ];
    }
}
