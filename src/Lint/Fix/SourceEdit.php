<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * A resolved, byte-addressed edit against a source file: replace the half-open byte range
 * `[$start, $end)` with `$replacement`.
 *
 * Every {@see FixOperation} lowers to one of these against a concrete source string, giving
 * {@see FixApplicator} a single uniform representation to order, de-conflict, and splice,
 * regardless of whether the originating operation was line- or node-oriented.
 *
 * @internal
 */
final readonly class SourceEdit
{
    public function __construct(
        public int $start,
        public int $end,
        public string $replacement,
    ) {}

    /**
     * Whether this edit's byte range overlaps `$other`'s.
     *
     * Ranges are half-open `[start, end)`; a zero-width insert (`start === end`) overlaps another
     * edit only when it falls strictly inside that edit's range, and two inserts at the same offset
     * are treated as overlapping so the applicator keeps just one.
     */
    public function overlaps(self $other): bool
    {
        if ($this->start === $this->end && $other->start === $other->end) {
            return $this->start === $other->start;
        }

        return $this->start < $other->end && $other->start < $this->end;
    }
}
