<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Override;

/**
 * Documentation-coverage summary for one lint run.
 *
 * An operation is "covered" when it carries no findings at the active level. Findings not
 * attributable to a single operation are counted in `$unattributedFindings` and do not lower
 * the percentage. `generatorVersion` is stamped so cross-version deltas are non-comparable.
 *
 * @internal
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class CoverageSummary implements Arrayable, JsonSerializable
{
    /**
     * `$perOperation` is held in memory only; {@see toArray()} omits it (Cobertura-style reports
     * read it directly, without it affecting JSON output).
     *
     * @param list<array{tag: string, total: int, covered: int, percent: float}> $perTag
     * @param list<array{file: ?string, line: ?int, covered: bool}>              $perOperation
     */
    public function __construct(
        public string $generatorVersion,
        public int $level,
        public int $totalOperations,
        public int $coveredOperations,
        public float $coveragePercent,
        public int $unattributedFindings,
        /**
         * @var list<array{tag: string, total: int, covered: int, percent: float}>
         */
        public array $perTag,
        /**
         * @var list<array{file: ?string, line: ?int, covered: bool}>
         */
        public array $perOperation = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    #[Override]
    public function toArray(): array
    {
        return [
            'generator_version' => $this->generatorVersion,
            'level' => $this->level,
            'total_operations' => $this->totalOperations,
            'covered_operations' => $this->coveredOperations,
            'coverage_percent' => $this->coveragePercent,
            'unattributed_findings' => $this->unattributedFindings,
            'per_tag' => $this->perTag,
        ];
    }
}
