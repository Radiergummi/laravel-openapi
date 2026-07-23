<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support;

/**
 * The outcome of reading a same-class response helper for its status. Three states:
 *  - {@see resolved()} — a statically-derived status for a body-less construction;
 *  - {@see refused()} — recognised as a body-less status helper, but a stated reason blocks it
 *    (non-readable status, a body-mutating chain, a delegating hop, a variable indirection); the
 *    caller logs the reason;
 *  - {@see skip()} — not a status helper the reader speaks to (a body-bearing construction, an
 *    unrecognised shape); the caller stays silent and falls through.
 *
 * @internal
 */
final readonly class SameClassHelperResult
{
    private function __construct(
        public ?int $status,
        public ?string $note,
    ) {}

    public static function resolved(int $status): self
    {
        return new self($status, null);
    }

    public static function refused(string $note): self
    {
        return new self(null, $note);
    }

    public static function skip(): self
    {
        return new self(null, null);
    }
}
