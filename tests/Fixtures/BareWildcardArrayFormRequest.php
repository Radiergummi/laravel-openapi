<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture exercising `attachments.*` where the parent `attachments` is not declared as its own
 * rule key. Laravel does not require the parent declaration; the schema must still emit an
 * `attachments` property of `type: array` with the wildcard rule's constraints applied to `items`.
 */
class BareWildcardArrayFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'attachments.*' => ['required', 'string', 'max:2048'],
        ];
    }
}
