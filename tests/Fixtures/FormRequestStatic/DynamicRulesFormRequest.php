<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\FormRequestStatic;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * Invoking rules() throws at spec-time and the body returns a computed value with no readable
 * literal: the static read finds nothing and the request still degrades.
 */
class DynamicRulesFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        if ($this->input('name') === null) {
            throw new RuntimeException('rules() requires request input at runtime');
        }

        return $this->buildRules();
    }

    /**
     * @return array<string, list<string>|string>
     */
    private function buildRules(): array
    {
        return ['name' => 'required'];
    }
}
