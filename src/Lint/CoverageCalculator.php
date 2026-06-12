<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Radiergummi\OpenApi\Enums\HttpMethod;

use function count;
use function ksort;
use function ltrim;
use function round;

/**
 * Derives a {@see CoverageSummary} from an in-scope operation set and the final finding list.
 *
 * Pure and container-free so it is unit-testable in isolation. The operation set is keyed by
 * {@see operationKey()}; the same helper computes each finding's key, so the two cannot diverge
 * on slash handling or separator format.
 *
 * @internal
 */
final readonly class CoverageCalculator
{
    private const string UNTAGGED_BUCKET = '(untagged)';

    /**
     * @param array<string, list<string>>                     $operationTags      operation
     *                                                                            key => its tag list (empty when
     *                                                                            untagged)
     * @param list<Finding>                                   $findings           the final,
     *                                                                            level-filtered finding list
     * @param array<string, array{file: ?string, line: ?int}> $operationLocations operation key
     *                                                                            => its source location, for the
     *                                                                            line-keyed reports. Optional: when
     *                                                                            omitted, the resulting
     *                                                                            {@see CoverageSummary::$perOperation}
     *                                                                            is empty and only the aggregate
     *                                                                            (JSON/gate) output is produced.
     */
    public function calculate(
        array $operationTags,
        array $findings,
        int $level,
        string $generatorVersion,
        array $operationLocations = [],
    ): CoverageSummary {
        $uncovered = [];
        $unattributed = 0;

        foreach ($findings as $finding) {
            $key = self::operationKey(
                $finding->spec,
                $finding->location->routeMethod,
                $finding->location->routeUri,
            );

            if ($key !== null && isset($operationTags[$key])) {
                $uncovered[$key] = true;
            } else {
                $unattributed++;
            }
        }

        $total = count($operationTags);
        $covered = $total - count($uncovered);

        return new CoverageSummary(
            generatorVersion: $generatorVersion,
            level: $level,
            totalOperations: $total,
            coveredOperations: $covered,
            coveragePercent: self::percent($covered, $total),
            unattributedFindings: $unattributed,
            perTag: $this->rollUpTags($operationTags, $uncovered),
            perOperation: $this->perOperation($operationTags, $operationLocations, $uncovered),
        );
    }

    /**
     * Stable operation key. Returns null when any part is missing (an unattributable finding).
     * The URI is leading-slash-trimmed to match the operation side.
     */
    public static function operationKey(?string $spec, ?HttpMethod $method, ?string $uri): ?string
    {
        if ($spec === null || $method === null || $uri === null) {
            return null;
        }

        return $spec . "\0" . $method->value . "\0" . ltrim($uri, '/');
    }

    private static function percent(int $covered, int $total): float
    {
        return $total === 0 ? 100.00 : round($covered / $total * 100, 2);
    }

    /**
     * @param array<string, list<string>> $operationTags
     * @param array<string, true>         $uncovered
     *
     * @return list<array{tag: string, total: int, covered: int, percent: float}>
     */
    private function rollUpTags(array $operationTags, array $uncovered): array
    {
        /** @var array<string, array{total: int, covered: int}> $buckets */
        $buckets = [];

        foreach ($operationTags as $key => $tags) {
            $names = $tags === [] ? [self::UNTAGGED_BUCKET] : $tags;
            $isCovered = !isset($uncovered[$key]);

            foreach ($names as $name) {
                $buckets[$name] ??= ['total' => 0, 'covered' => 0];
                $buckets[$name]['total']++;

                if ($isCovered) {
                    $buckets[$name]['covered']++;
                }
            }
        }

        ksort($buckets);

        $rows = [];

        foreach ($buckets as $tag => $counts) {
            $rows[] = [
                'tag' => $tag,
                'total' => $counts['total'],
                'covered' => $counts['covered'],
                'percent' => self::percent($counts['covered'], $counts['total']),
            ];
        }

        return $rows;
    }

    /**
     * Build the per-operation line-coverage records, in operation-iteration order. An operation
     * with no recorded location carries null file/line — the line-keyed formatter excludes those
     * (inference-only / closure-routed operations have no single source line).
     *
     * @param array<string, list<string>>                     $operationTags
     * @param array<string, array{file: ?string, line: ?int}> $operationLocations
     * @param array<string, true>                             $uncovered
     *
     * @return list<array{file: ?string, line: ?int, covered: bool}>
     */
    private function perOperation(array $operationTags, array $operationLocations, array $uncovered): array
    {
        if ($operationLocations === []) {
            return [];
        }

        $rows = [];

        foreach ($operationTags as $key => $_tags) {
            $location = $operationLocations[$key] ?? ['file' => null, 'line' => null];

            $rows[] = [
                'file' => $location['file'],
                'line' => $location['line'],
                'covered' => !isset($uncovered[$key]),
            ];
        }

        return $rows;
    }
}
