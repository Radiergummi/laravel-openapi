<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture FormRequest whose rule list contains a custom {@see ValidationRule} the schema mapper
 * cannot introspect. Generation emits a route-scoped `rule.unknown` finding for it — a finding
 * that historically carried no `routeUri`, which is the leak exercised by the `--path` scoping
 * regression test (#50).
 */
final class UnknownRuleFormRequest extends FormRequest
{
    /**
     * @return array<string, list<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'color' => [
                'required',
                new class () implements ValidationRule {
                    public function validate(string $attribute, mixed $value, Closure $fail): void {}
                },
            ],
        ];
    }
}
