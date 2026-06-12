<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

/**
 * Result of scanning a controller method for inline validation rules.
 *
 * A null result from the reader means no whitelisted validator call was found (nothing to say);
 * an instance with `rules === null` means a call **was** found but its rules could not be read
 * statically — the degrade case, which warrants a generation-log note.
 *
 * @internal
 */
final readonly class InlineValidationScanResult
{
    /**
     * @param null|array<string, array<int, mixed>|string> $rules         the recovered rules,
     *                                                                    keyed by field path
     * @param array<string, string>                        $descriptions  trailing-comment field
     *                                                                    descriptions, keyed by
     *                                                                    field path
     * @param list<string>                                 $skippedFields fields dropped because
     *                                                                    their rules were not
     *                                                                    literal
     */
    private function __construct(
        public ?array $rules,
        public array $descriptions = [],
        public array $skippedFields = [],
        public ?string $degradeReason = null,
    ) {}

    /**
     * @param array<string, array<int, mixed>|string> $rules
     * @param array<string, string>                   $descriptions
     * @param list<string>                            $skippedFields
     */
    public static function recovered(array $rules, array $descriptions = [], array $skippedFields = []): self
    {
        return new self($rules, $descriptions, $skippedFields);
    }

    public static function degraded(string $reason): self
    {
        return new self(null, degradeReason: $reason);
    }
}
