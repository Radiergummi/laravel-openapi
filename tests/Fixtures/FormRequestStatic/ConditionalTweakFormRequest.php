<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * Invoking rules() throws at spec-time. The base literal is assigned to a variable, then a
 * conditional block tweaks one entry via `$rules['action'] = …`. The static read recovers the
 * never-wrong base literal and ignores the conditional override.
 */
class ConditionalTweakFormRequest extends FormRequest
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

        if ($this->boolean('convert')) {
            $rules['action'] = ['required'];
        }

        return $rules;
    }
}
