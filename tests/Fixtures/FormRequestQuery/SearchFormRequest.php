<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestQuery;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A search FormRequest exercising the query-parameter flattener: a required scalar, an optional
 * scalar, a nested object (`filter.name` → `filter[name]`), and a scalar array (`ids.*` → `ids[]`).
 */
class SearchFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'term' => ['required', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'filter' => ['sometimes', 'array'],
            'filter.name' => ['nullable', 'string'],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['integer'],
        ];
    }
}
