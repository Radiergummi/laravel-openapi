<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * A byte-addressed edit: replace the half-open range `[$start, $end)` with `$replacement`.
 * {@see FixApplicator} orders and de-conflicts these before splicing.
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
     * Returns true when this edit's range overlaps `$other`'s. Two zero-width inserts at the same
     * offset are treated as overlapping so the applicator keeps just one.
     */
    public function overlaps(self $other): bool
    {
        if ($this->start === $this->end && $other->start === $other->end) {
            return $this->start === $other->start;
        }

        return $this->start < $other->end && $other->start < $this->end;
    }
}
