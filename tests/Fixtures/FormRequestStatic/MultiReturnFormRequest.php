<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * Invoking rules() throws at spec-time, and the body has more than one return: which one is *the*
 * rules array is a guess, so the static read refuses and the request degrades.
 */
class MultiReturnFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        if ($this->input('mode') === null) {
            throw new RuntimeException('rules() requires request input at runtime');
        }

        if ($this->boolean('admin')) {
            return ['admin' => 'required'];
        }

        return ['guest' => 'required'];
    }
}
