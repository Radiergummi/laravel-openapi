<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors a real-world FormRequest whose rules() reads a route-bound model and
 * uses one of its properties inside a `Rule::in([...])`.
 */
class RouteBoundFormRequest extends FormRequest
{
    public function rules(): array
    {
        $contactInfoRequest = $this->route('contactInfoRequest');

        return [
            'status' => ['required', 'string'],
            'request_uuid' => ['required', 'uuid', Rule::in([$contactInfoRequest->uuid])],
            'group_uuid' => ['required', 'uuid'],
            'error' => ['nullable', 'string', 'max:65535'],
        ];
    }
}
