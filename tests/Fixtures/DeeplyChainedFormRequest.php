<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Stress test: rules() chains multiple method/property accesses through `$this->user()`.
 * Real-world equivalent: `$this->user()->team->members->pluck('id')->toArray()`.
 */
class DeeplyChainedFormRequest extends FormRequest
{
    public function rules(): array
    {
        $allowedIds = $this->user()->team->members->pluck('id')->toArray();

        return [
            'assigned_to' => ['required', 'integer', Rule::in($allowedIds)],
            'note' => ['nullable', 'string'],
        ];
    }
}
