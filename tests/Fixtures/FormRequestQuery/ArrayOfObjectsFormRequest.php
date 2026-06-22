<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestQuery;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A FormRequest with an array-of-objects rule (`items.*.id`), which has no valid query-string
 * representation and must be dropped with a notice when surfaced as query parameters.
 */
class ArrayOfObjectsFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['required', 'integer'],
        ];
    }
}
