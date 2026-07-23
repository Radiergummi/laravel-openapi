<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * An unconditional `$rules[…] = …` adds a field to the base literal. The addition is a superset, so
 * the base literal stays never-wrong and is kept (only value-replacing rebindings are refused).
 */
class UnconditionalTweakFormRequest extends FormRequest
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
        ];

        $rules['send_email'] = 'sometimes|boolean';

        return $rules;
    }
}
