<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Override;

/**
 * Documentation-coverage summary for one {@see LintRunner::run()} invocation.
 *
 * Coverage is operation-level and binary: an operation (route × verb) is covered when it carries
 * no findings at the active level. Findings that cannot be attributed to a single operation
 * (schema-derived, pre-build, spec-level) are counted in {@see $unattributedFindings} and do not
 * lower the percentage. `generatorVersion` is stamped so cross-version deltas are recognised as
 * non-comparable (the shifting-denominator footgun).
 *
 * @internal
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class CoverageSummary implements Arrayable, JsonSerializable
{
    /**
     * @param list<array{tag: string, total: int, covered: int, percent: float}> $perTag
     * @param list<array{file: ?string, line: ?int, covered: bool}>              $perOperation
     *                                                                                         per-operation source location + covered flag for line-keyed reports (Cobertura/LCOV).
     *                                                                                         Held in memory only; deliberately omitted from {@see toArray()} so the JSON report and
     *                                                                                         the coverage gate stay byte-identical to the pre-#153 aggregate output.
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
