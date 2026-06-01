<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture exercising Laravel wildcard rule keys: a bare `*` at the root (additionalProperties),
 * and `attachments.*` where the parent `attachments` is not separately declared (items synthesis).
 */
class WildcardFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            '*' => ['required', 'uuid'],
        ];
    }
}
