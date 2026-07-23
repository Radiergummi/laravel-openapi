<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * A `foreach` reuses the `$rules` name as its loop target, replacing the base literal wholesale. The
 * base literal has gone stale, so the static read refuses rather than reporting it.
 */
class RebindFormRequest extends FormRequest
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

        $overrides = [['name' => 'required|string']];

        foreach ($overrides as $rules) {
        }

        return $rules;
    }
}
