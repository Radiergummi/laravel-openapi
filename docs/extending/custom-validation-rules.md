# Custom validation rules

When you write a custom Laravel `Rule` or `ValidationRule` class, the OpenAPI generator cannot
infer any schema constraint from it. By default, the unknown rule object is silently skipped and
a `rule.unknown` lint finding is emitted.

Implement the `SelfDocumentingRule` interface to declare the schema constraint the rule
represents:

```php
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Radiergummi\OpenApi\Core\Extractors\RuleDocumentation;
use Radiergummi\OpenApi\Core\Extractors\SelfDocumentingRule;

final class IsbnRule implements ValidationRule, SelfDocumentingRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // … your validation logic …
    }

    public function documentation(): RuleDocumentation
    {
        return new RuleDocumentation(
            description: 'ISBN-10 or ISBN-13 with optional hyphens.',
            type: 'string',
            pattern: '^(\\d{9}[\\dX]|\\d{13})$',
        );
    }
}
```

The rule above causes the generator to set `type: string` and `pattern: …` on the corresponding
property schema, and appends the description text.

## Gap-filling semantics

Rule self-documentation **fills gaps** — it does not overwrite a constraint already set by
another rule on the same field. If a sibling rule (e.g. `'string'`) has already established
`type: string`, `SelfDocumentingRule::documentation()` returning `type: 'string'` is a no-op for
that field. The same applies to every other field (`format`, `pattern`, `enum`,
`minLength`/`maxLength`, `minimum`/`maximum`).

`description`, however, is always appended rather than replaced, so a rule can add extra
human-readable context alongside an already-derived description.

## Available fields

| Field | Type | Notes |
|---|---|---|
| `description` | `string\|null` | Appended to any pre-existing description. |
| `type` | `string\|null` | `string`, `integer`, `number`, `boolean`, `array`. |
| `format` | `string\|null` | e.g. `date`, `uuid`, `email`. |
| `pattern` | `string\|null` | ECMA regex pattern (no delimiter). |
| `enum` | `list<string\|int\|float>\|null` | Allowed values. |
| `minLength` | `int\|null` | Minimum string length. |
| `maxLength` | `int\|null` | Maximum string length. |
| `minimum` | `int\|float\|null` | Numeric minimum. |
| `maximum` | `int\|float\|null` | Numeric maximum. |

`minItems` and `maxItems` are intentionally absent: array-typed custom rules are best served by combining the built-in `array` rule with `min`/`max`/`size` rules on the array field itself, rather than embedding array-length constraints inside a custom rule object.
