<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

/**
 * The raw outcome of reading a {@see \Illuminate\Foundation\Http\FormRequest}'s `rules()`.
 *
 * `rules` is the unmapped array returned by `rules()`; consumers run it through
 * {@see \Radiergummi\OpenApi\Support\Extraction\ValidationRulesToSchema::process()} themselves. A null
 * `rules` with a `degradeReason` means `rules()` could not be read (it threw at spec-time).
 *
 * @internal
 */
final readonly class FormRequestRulesResult
{
    /**
     * @param null|array<string, array<int, mixed>|string> $rules
     */
    private function __construct(
        public ?array $rules,
        public ?string $degradeReason = null,
    ) {}

    /**
     * @param array<string, array<int, mixed>|string> $rules
     */
    public static function recovered(array $rules): self
    {
        return new self($rules);
    }

    public static function degraded(string $reason): self
    {
        return new self(null, $reason);
    }
}
