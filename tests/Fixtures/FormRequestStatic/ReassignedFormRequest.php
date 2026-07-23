<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * The `$rules` variable is assigned twice; which literal is *the* rules array is a guess, so the
 * static read refuses.
 */
class ReassignedFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        if ($this->input('action') === null) {
            throw new RuntimeException('rules() requires request input at runtime');
        }

        $rules = [
            'action' => 'sometimes|string',
        ];

        $rules = [
            'name' => 'required|string',
        ];

        return $rules;
    }
}
