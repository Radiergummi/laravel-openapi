<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * Invoking rules() throws at spec-time, but the body returns a bare array literal directly.
 */
class BareReturnFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        if ($this->input('name') === null) {
            throw new RuntimeException('rules() requires request input at runtime');
        }

        return [
            'name' => 'required|string',
            'email' => ['required', 'email'],
        ];
    }
}
