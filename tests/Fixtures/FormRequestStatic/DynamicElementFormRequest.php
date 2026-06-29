<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Invoking rules() throws at spec-time. One field's base literal mixes literal rules with a
 * dynamic `Rule::in(...)` element; the per-field tolerance keeps the literal rules and drops the
 * dynamic one.
 */
class DynamicElementFormRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->input('foo') === null) {
            throw new RuntimeException('rules() requires request input at runtime');
        }

        $rules = [
            'foo' => ['required', Rule::in(['a', 'b'])],
            'bar' => 'string',
        ];

        return $rules;
    }
}
