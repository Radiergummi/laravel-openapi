<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;

use function array_merge;

/**
 * rules() invokes cleanly (the primary path) but returns a computed array with no readable
 * literal: the static fallback is never consulted and the rules come straight from invocation.
 */
class ComputedRulesFormRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return array_merge(['a' => 'required'], ['b' => 'string']);
    }
}
