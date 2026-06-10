<?php

declare(strict_types=1);

/**
 * Survey results aggregator — merges a run's per-app metric entries into the existing
 * results.json by app name, in corpus order.
 *
 * surveyMergeResults() is the pure contract: (existing, fresh, orderNames) -> merged.
 * A full corpus run replaces every entry; `corpus.sh --only <app>` replaces just that
 * app and preserves the rest, instead of clobbering the file with a single-app array (#229).
 *
 * Run as a CLI it reads the corpus pin registry, the existing results.json (if any), and a
 * file of the current run's entries, then prints the merged array as validated JSON. A
 * pre-existing results.json that does not parse (e.g. truncated/garbled by an aborted or
 * concurrent run, #231) is discarded with a warning and rebuilt from this run.
 *
 * Usage: php aggregate.php <corpus.json> <results.json> <fresh-entries.json>
 */

/**
 * Merge the current run's entries into the existing aggregate, keyed by app name (fresh wins),
 * emitted in corpus order. Entries whose app is no longer in the corpus keep a stable tail slot.
 *
 * @param list<array<string, mixed>> $existing   entries already in results.json
 * @param list<array<string, mixed>> $fresh      entries produced by the current run
 * @param list<string>               $orderNames corpus app names, in canonical order
 *
 * @return list<array<string, mixed>>
 */
function surveyMergeResults(array $existing, array $fresh, array $orderNames): array
{
    $byName = [];

    // Existing first, then fresh — last write wins, so a fresh entry replaces its prior one.
    foreach (array_merge($existing, $fresh) as $entry) {
        if (isset($entry['name'])) {
            $byName[$entry['name']] = $entry;
        }
    }

    $merged = [];

    foreach ($orderNames as $name) {
        if (isset($byName[$name])) {
            $merged[] = $byName[$name];
            unset($byName[$name]);
        }
    }

    // Any entry whose app is no longer pinned in the corpus is kept rather than dropped.
    foreach ($byName as $entry) {
        $merged[] = $entry;
    }

    return $merged;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $corpusPath = $argv[1] ?? null;
    $resultsPath = $argv[2] ?? null;
    $freshPath = $argv[3] ?? null;

    if ($corpusPath === null || $resultsPath === null || $freshPath === null) {
        fwrite(STDERR, "usage: aggregate.php <corpus.json> <results.json> <fresh-entries.json>\n");
        exit(2);
    }

    $corpus = json_decode((string) @file_get_contents($corpusPath), true);

    if (!is_array($corpus['apps'] ?? null)) {
        fwrite(STDERR, "aggregate.php: cannot read corpus apps from {$corpusPath}\n");
        exit(1);
    }

    $orderNames = array_map(static fn(array $app): string => (string) $app['name'], $corpus['apps']);

    $existing = [];

    if (is_file($resultsPath)) {
        $decoded = json_decode((string) file_get_contents($resultsPath), true);

        if (is_array($decoded)) {
            $existing = $decoded;
        } else {
            fwrite(STDERR, "aggregate.php: existing {$resultsPath} did not parse — discarding it and rebuilding from this run\n");
        }
    }

    $fresh = json_decode((string) @file_get_contents($freshPath), true);

    if (!is_array($fresh)) {
        fwrite(STDERR, "aggregate.php: fresh entries at {$freshPath} did not parse as JSON\n");
        exit(1);
    }

    echo json_encode(
        surveyMergeResults($existing, $fresh, $orderNames),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ) . "\n";
}
