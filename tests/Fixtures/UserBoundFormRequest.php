<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors matchory-webapp's `UpdateCurrentCustomerRequest`: rules() reads `$this->user()` to
 * scope a `unique` rule.
 */
class UserBoundFormRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
            'customer_id' => ['required', 'integer', Rule::in([$this->user()->customer_id])],
        ];
    }
}
