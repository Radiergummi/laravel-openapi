<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * Invoking rules() throws on the runtime-state read at the top (the request input is absent at
 * spec-time), but the rules themselves are a static literal assigned to a variable that is then
 * returned: the primary corpus shape.
 */
class VariableReturnFormRequest extends FormRequest
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
            'ids' => 'required|array',
            'send_email' => 'sometimes|boolean',
        ];

        return $rules;
    }
}
