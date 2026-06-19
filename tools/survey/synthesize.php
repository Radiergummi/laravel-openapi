<?php

declare(strict_types=1);

/**
 * Survey Layer B2 synthesis — corpus synthesis + report-candidate emission (deterministic).
 *
 * surveySynthesize() is the pure contract: (results, manifest, lifts) -> a structured synthesis
 * record. surveyRenderPublicCandidate()/surveyRenderInternalCandidate() turn that record into the
 * two Markdown candidates. All three are wall-clock-free — every timestamp comes FROM manifest.json,
 * never date()/time() — so the same inputs reproduce a ===-identical record and byte-identical
 * Markdown. The CLI tail reads the Layer A aggregate (results.json + manifest.json) and the per-app
 * Layer B1 lift.json files from $WS, writes the two candidates into $WS, and prints their paths. It
 * writes ONLY to $WS — never git add, never touch the repo tree, never the curated docs/field-report.
 *
 * Usage: php synthesize.php <ws-dir>
 *   <ws-dir> holds results.json, manifest.json, and apps/<App>/lift.json (written by the corpus run
 *   and the Layer B1 lift workflow).
 */

// region classification thresholds
// Fixed constants so the response-spectrum label is reproducible. The raw ratios are surfaced
// alongside the label so a reader can audit the call. Tuned against the corpus, not per-app.
const SURVEY_FULL_SCHEMA_RATIO = 0.7;   // responseSchemas / apiOperations at/above this -> full-schema
const SURVEY_NO_BODY_RATIO = 0.05;      // responseSchemas / apiOperations at/below this -> no-body
// endregion

/**
 * Classify one app on the response-coverage spectrum from its metrics.
 *
 * A blocked-compat boot wins outright: the app never generated, so its zero responseSchemas is the
 * absence of a run, not a thin spec, and must not be mislabeled no-body.
 *
 * @param array<string, mixed> $metrics
 */
function survey_classify(array $metrics): string
{
    if (($metrics['crash']['bootOutcome'] ?? null) === 'blocked-compat') {
        return 'blocked';
    }

    $apiOperations = (int) ($metrics['apiOperations'] ?? 0);
    $responseSchemas = (int) ($metrics['responseSchemas'] ?? 0);
    $documentedResponses = (int) ($metrics['documentedResponses'] ?? 0);

    if ($apiOperations === 0) {
        return 'no-body';
    }

    $schemaRatio = $responseSchemas / $apiOperations;

    if ($schemaRatio >= SURVEY_FULL_SCHEMA_RATIO) {
        return 'full-schema';
    }

    if ($schemaRatio <= SURVEY_NO_BODY_RATIO) {
        return 'no-body';
    }

    // Between the bands: documents many 2xx outcomes but few carry a substantive schema -> the
    // empty-envelope middle ground (the field report's three-point spectrum).
    return $documentedResponses > $responseSchemas ? 'envelope-empty' : 'no-body';
}

/**
 * Rank a rule_id -> count map by a TOTAL order: count DESC, then rule-ID ASC. Never relies on PHP
 * insertion order or arsort (whose equal-count ordering is input-dependent), so the rendered output
 * is byte-stable across equivalent inputs.
 *
 * @param array<string, int> $byRule
 *
 * @return list<array{rule: string, count: int}>
 */
function survey_rankByRule(array $byRule): array
{
    $ranked = [];

    foreach ($byRule as $rule => $count) {
        $ranked[] = ['rule' => (string) $rule, 'count' => (int) $count];
    }

    usort($ranked, static function (array $a, array $b): int {
        return $b['count'] <=> $a['count'] ?: strcmp($a['rule'], $b['rule']);
    });

    return $ranked;
}

/**
 * Compute the structured synthesis record from the merged survey artifacts.
 *
 * @param list<array<string, mixed>> $results  results.json — per-app {name, metrics}, corpus order
 * @param array<string, mixed>       $manifest manifest.json — provenance {generatedAt, libraryCommit, apps}
 * @param list<array<string, mixed>> $lifts    per-app lift.json summaries (optional, any subset of apps)
 *
 * @return array<string, mixed>
 */
function surveySynthesize(array $results, array $manifest, array $lifts): array
{
    $apps = [];
    $totalPaths = 0;
    $totalOperations = 0;
    $totalApiOperations = 0;
    $totalResponseSchemas = 0;
    $totalDocumentedResponses = 0;
    $totalRequestBodies = 0;
    $totalComponentSchemas = 0;
    $cleanRuns = 0;
    $byRuleTotals = [];

    foreach ($results as $entry) {
        $name = (string) ($entry['name'] ?? '');
        $metrics = is_array($entry['metrics'] ?? null) ? $entry['metrics'] : [];

        if ($name === '') {
            continue;
        }

        $totalPaths += (int) ($metrics['paths'] ?? 0);
        $totalOperations += (int) ($metrics['operations'] ?? 0);
        $totalApiOperations += (int) ($metrics['apiOperations'] ?? 0);
        $totalResponseSchemas += (int) ($metrics['responseSchemas'] ?? 0);
        $totalDocumentedResponses += (int) ($metrics['documentedResponses'] ?? 0);
        $totalRequestBodies += (int) ($metrics['requestBodies'] ?? 0);
        $totalComponentSchemas += (int) ($metrics['componentSchemas'] ?? 0);

        $crash = is_array($metrics['crash'] ?? null) ? $metrics['crash'] : [];

        if (($crash['generateExit'] ?? -1) === 0 && ($crash['lintExit'] ?? -1) === 0) {
            $cleanRuns++;
        }

        foreach ((array) ($metrics['lintFindings']['byRule'] ?? []) as $rule => $count) {
            $byRuleTotals[(string) $rule] = ($byRuleTotals[(string) $rule] ?? 0) + (int) $count;
        }

        $app = [
            'name' => $name,
            'classification' => survey_classify($metrics),
            'bootOutcome' => (string) ($crash['bootOutcome'] ?? 'unknown'),
            'ratios' => [
                'responseSchemas' => (int) ($metrics['responseSchemas'] ?? 0),
                'documentedResponses' => (int) ($metrics['documentedResponses'] ?? 0),
                'apiOperations' => (int) ($metrics['apiOperations'] ?? 0),
            ],
            'metrics' => $metrics,
        ];

        // The coverage block (app's-own-published-spec self-comparison) rides along only where
        // metrics.php computed one. It is internal-only — the public renderer never reads it.
        if (is_array($metrics['coverage'] ?? null)) {
            $app['coverage'] = $metrics['coverage'];
        }

        $apps[] = $app;
    }

    // Provenance is carried verbatim from the manifest so each candidate is self-verifying.
    $provenanceApps = [];

    foreach ((array) ($manifest['apps'] ?? []) as $app) {
        if (!is_array($app)) {
            continue;
        }

        $provenanceApps[] = [
            'name' => (string) ($app['name'] ?? ''),
            'pinnedSha' => $app['pinnedSha'] ?? null,
            'actualSha' => $app['actualSha'] ?? null,
            'installedLibrarySha' => $app['installedLibrarySha'] ?? null,
            'php' => (string) ($app['php'] ?? ''),
        ];
    }

    return [
        'provenance' => [
            'generatedAt' => (string) ($manifest['generatedAt'] ?? ''),
            'libraryCommit' => (string) ($manifest['libraryCommit'] ?? ''),
            'apps' => $provenanceApps,
        ],
        'corpus' => [
            'appCount' => count($apps),
            'cleanRuns' => $cleanRuns,
            'totalPaths' => $totalPaths,
            'totalOperations' => $totalOperations,
            'totalApiOperations' => $totalApiOperations,
            'totalResponseSchemas' => $totalResponseSchemas,
            'totalDocumentedResponses' => $totalDocumentedResponses,
            'totalRequestBodies' => $totalRequestBodies,
            'totalComponentSchemas' => $totalComponentSchemas,
        ],
        'apps' => $apps,
        'recurringGaps' => [
            'byRule' => survey_rankByRule($byRuleTotals),
            'inventory' => survey_gapInventory($lifts),
        ],
        'lift' => survey_liftBreakdown($lifts),
    ];
}

/**
 * Union the per-app B1 gap inventories, deduped by title, recording which apps hit each gap.
 * Ranked by a total order: app-count DESC, then title ASC.
 *
 * @param list<array<string, mixed>> $lifts
 *
 * @return list<array{title: string, apps: list<string>}>
 */
function survey_gapInventory(array $lifts): array
{
    $byTitle = [];

    foreach ($lifts as $lift) {
        $app = (string) ($lift['app'] ?? '');

        foreach ((array) ($lift['gapsEncountered'] ?? []) as $gap) {
            $title = is_array($gap) ? (string) ($gap['title'] ?? '') : (string) $gap;

            if ($title === '') {
                continue;
            }

            $byTitle[$title] ??= [];

            if ($app !== '' && !in_array($app, $byTitle[$title], true)) {
                $byTitle[$title][] = $app;
            }
        }
    }

    $inventory = [];

    foreach ($byTitle as $title => $apps) {
        $inventory[] = ['title' => $title, 'apps' => $apps];
    }

    usort($inventory, static function (array $a, array $b): int {
        return count($b['apps']) <=> count($a['apps']) ?: strcmp($a['title'], $b['title']);
    });

    return $inventory;
}

/**
 * Per-app annotation-lift breakdown, in lift-input order. Harvest is tracked separately from
 * authored attributes — it is transcribed from the app's own published spec, never inferred.
 *
 * @param list<array<string, mixed>> $lifts
 *
 * @return list<array<string, mixed>>
 */
function survey_liftBreakdown(array $lifts): array
{
    $breakdown = [];

    foreach ($lifts as $lift) {
        $applied = is_array($lift['attributesApplied'] ?? null) ? $lift['attributesApplied'] : [];

        $breakdown[] = [
            'app' => (string) ($lift['app'] ?? ''),
            'baseline' => is_array($lift['baseline'] ?? null) ? $lift['baseline'] : [],
            'afterHarvest' => is_array($lift['afterHarvest'] ?? null) ? $lift['afterHarvest'] : [],
            'afterAgent' => is_array($lift['afterAgent'] ?? null) ? $lift['afterAgent'] : [],
            'harvested' => (int) ($applied['harvested']['total'] ?? 0),
            'authored' => count((array) ($applied['authored'] ?? [])),
        ];
    }

    return $breakdown;
}

// region renderers

/** Human label for a response-spectrum classification. */
function survey_classificationLabel(string $classification): string
{
    return match ($classification) {
        'full-schema' => 'full-schema',
        'envelope-empty' => 'envelope, empty body',
        'no-body' => 'no response body',
        'blocked' => 'blocked (compat)',
        default => $classification,
    };
}

/**
 * Render the provenance manifest block both candidates embed verbatim.
 *
 * @param array<string, mixed> $provenance
 */
function survey_renderProvenance(array $provenance): string
{
    $lines = [
        '## Provenance',
        '',
        sprintf('- Library commit: `%s`', $provenance['libraryCommit']),
        sprintf('- Generated at: `%s`', $provenance['generatedAt']),
        '',
        '| App | Pinned SHA | Actual SHA | Installed library | PHP |',
        '|---|---|---|---|---|',
    ];

    foreach ($provenance['apps'] as $app) {
        $lines[] = sprintf(
            '| %s | `%s` | `%s` | `%s` | %s |',
            $app['name'],
            $app['pinnedSha'] ?? '—',
            $app['actualSha'] ?? '—',
            $app['installedLibrarySha'] ?? '—',
            $app['php'],
        );
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Render the public field-report candidate: corpus table + robustness rollup + the spectrum
 * classification grounded in measured numbers + provenance. NO published-spec/coverage numbers and
 * no competitor framing. Wrapped in the #159 publication-gate banner so it cannot ship un-cleared.
 *
 * @param array<string, mixed> $synthesis
 */
function surveyRenderPublicCandidate(array $synthesis): string
{
    $corpus = $synthesis['corpus'];

    $out = [];
    $out[] = '<!--';
    $out[] = 'PUBLICATION GATE: this is a generated CANDIDATE for maintainer review, not the published';
    $out[] = 'report. App names are rendered as-is from the corpus. Before publishing, obtain maintainer';
    $out[] = 'permission for each named app or anonymize it to its category. Tracked in issue #159. No';
    $out[] = 'head-to-head third-party numbers.';
    $out[] = '-->';
    $out[] = '';
    $out[] = '# Field report candidate: laravel-openapi against real-world APIs';
    $out[] = '';
    $out[] = sprintf(
        'Black-box run against %d open-source Laravel applications — %d total routes across %d API operations.',
        $corpus['appCount'],
        $corpus['totalPaths'],
        $corpus['totalApiOperations'],
    );
    $out[] = '';
    $out[] = '## Corpus';
    $out[] = '';
    $out[] = '| Application | API ops | Response schemas | Request bodies | Component schemas | Response shape |';
    $out[] = '|---|--:|--:|--:|--:|---|';

    foreach ($synthesis['apps'] as $app) {
        $metrics = $app['metrics'];
        $out[] = sprintf(
            '| %s | %d | %d | %d | %d | %s |',
            $app['name'],
            (int) ($metrics['apiOperations'] ?? 0),
            (int) ($metrics['responseSchemas'] ?? 0),
            (int) ($metrics['requestBodies'] ?? 0),
            (int) ($metrics['componentSchemas'] ?? 0),
            survey_classificationLabel($app['classification']),
        );
    }

    $out[] = '';
    $out[] = '## Robustness';
    $out[] = '';
    $out[] = sprintf('- Apps that generated and linted cleanly: **%d / %d**', $corpus['cleanRuns'], $corpus['appCount']);
    $out[] = sprintf('- Operations with a substantive 2xx response schema: **%d / %d**', $corpus['totalResponseSchemas'], $corpus['totalApiOperations']);
    $out[] = sprintf('- Operations documenting any 2xx outcome: **%d / %d**', $corpus['totalDocumentedResponses'], $corpus['totalApiOperations']);
    $out[] = sprintf('- Operations with a request body: **%d**', $corpus['totalRequestBodies']);
    $out[] = '';
    $out[] = '## Response spectrum';
    $out[] = '';
    $out[] = 'Each app is classified from its measured `responseSchemas / apiOperations` ratio:';
    $out[] = '';
    $out[] = '| Application | Classification | responseSchemas | documentedResponses | apiOperations |';
    $out[] = '|---|---|--:|--:|--:|';

    foreach ($synthesis['apps'] as $app) {
        $ratios = $app['ratios'];
        $out[] = sprintf(
            '| %s | %s | %d | %d | %d |',
            $app['name'],
            survey_classificationLabel($app['classification']),
            $ratios['responseSchemas'],
            $ratios['documentedResponses'],
            $ratios['apiOperations'],
        );
    }

    $out[] = '';
    $out[] = survey_renderProvenance($synthesis['provenance']);

    return implode("\n", $out);
}

/**
 * Render the internal synthesis candidate: the full picture, including the coverage block (the
 * app's-OWN-published-spec self-comparison metrics.php computes, NOT a third-party benchmark), the
 * recurring-gap rollup, the deduped B1 gap inventory, and the per-app annotation-lift breakdown.
 *
 * @param array<string, mixed> $synthesis
 */
function surveyRenderInternalCandidate(array $synthesis): string
{
    $out = [];
    $out[] = '# Internal synthesis candidate — laravel-openapi survey';
    $out[] = '';
    $out[] = 'Maintainer-only working substrate for the field report. Coverage figures below are each';
    $out[] = "app's comparison against **its own published spec**, not any third-party benchmark.";
    $out[] = '';
    $out[] = '## Coverage vs each app\'s own published spec';
    $out[] = '';
    $out[] = '| Application | Published ops | Our ops | In both | Coverage % |';
    $out[] = '|---|--:|--:|--:|--:|';

    $anyCoverage = false;

    foreach ($synthesis['apps'] as $app) {
        if (!isset($app['coverage'])) {
            continue;
        }

        $anyCoverage = true;
        $coverage = $app['coverage'];
        $out[] = sprintf(
            '| %s | %d | %d | %d | %s |',
            $app['name'],
            (int) ($coverage['publishedOps'] ?? 0),
            (int) ($coverage['ours'] ?? 0),
            (int) ($coverage['intersection'] ?? 0),
            (string) ($coverage['covPercent'] ?? '0'),
        );
    }

    if (!$anyCoverage) {
        $out[] = '| _no app pinned a published spec_ | | | | |';
    }

    $out[] = '';
    $out[] = '## Recurring lint findings (corpus rollup)';
    $out[] = '';
    $out[] = '| Rule | Total findings |';
    $out[] = '|---|--:|';

    foreach ($synthesis['recurringGaps']['byRule'] as $row) {
        $out[] = sprintf('| `%s` | %d |', $row['rule'], $row['count']);
    }

    if ($synthesis['recurringGaps']['byRule'] === []) {
        $out[] = '| _no findings_ | |';
    }

    $out[] = '';
    $out[] = '## Attribute-surface gap inventory (from Layer B1)';
    $out[] = '';
    $out[] = '| Gap | Apps affected |';
    $out[] = '|---|---|';

    foreach ($synthesis['recurringGaps']['inventory'] as $gap) {
        $out[] = sprintf('| %s | %s |', $gap['title'], implode(', ', $gap['apps']));
    }

    if ($synthesis['recurringGaps']['inventory'] === []) {
        $out[] = '| _no gaps recorded_ | |';
    }

    $out[] = '';
    $out[] = '## Annotation lift (per app, from Layer B1)';
    $out[] = '';
    $out[] = '| Application | Baseline % | After harvest % | After agent % | Harvested attrs | Authored attrs |';
    $out[] = '|---|--:|--:|--:|--:|--:|';

    foreach ($synthesis['lift'] as $lift) {
        $out[] = sprintf(
            '| %s | %s | %s | %s | %d | %d |',
            $lift['app'],
            (string) ($lift['baseline']['completenessPercent'] ?? '—'),
            (string) ($lift['afterHarvest']['completenessPercent'] ?? '—'),
            (string) ($lift['afterAgent']['completenessPercent'] ?? '—'),
            $lift['harvested'],
            $lift['authored'],
        );
    }

    if ($synthesis['lift'] === []) {
        $out[] = '| _no lift measured_ | | | | | |';
    }

    $out[] = '';
    $out[] = 'Harvested attributes are transcribed from each app\'s own published spec; authored';
    $out[] = 'attributes are added by the annotation pass. The two are tracked separately.';
    $out[] = '';
    $out[] = survey_renderProvenance($synthesis['provenance']);

    return implode("\n", $out);
}

// endregion

// CLI entry — only when invoked directly, so the file is safe to require in tests.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $workspace = $argv[1] ?? getenv('WS') ?: null;

    if ($workspace === null || !is_dir($workspace)) {
        fwrite(STDERR, "usage: synthesize.php <ws-dir>  (or set WS)\n");
        exit(2);
    }

    $results = json_decode((string) @file_get_contents("$workspace/results.json"), true);
    $manifest = json_decode((string) @file_get_contents("$workspace/manifest.json"), true);

    if (!is_array($results) || !is_array($manifest)) {
        fwrite(STDERR, "synthesize.php: could not read results.json + manifest.json from {$workspace}\n");
        exit(1);
    }

    $lifts = [];

    foreach (glob("$workspace/apps/*/lift.json") ?: [] as $liftPath) {
        $lift = json_decode((string) file_get_contents($liftPath), true);

        if (is_array($lift)) {
            $lifts[] = $lift;
        }
    }

    // Stable input order so the synthesis (and thus the rendered Markdown) is reproducible
    // regardless of the filesystem's glob ordering.
    usort($lifts, static fn(array $a, array $b): int => strcmp((string) ($a['app'] ?? ''), (string) ($b['app'] ?? '')));

    $synthesis = surveySynthesize($results, $manifest, $lifts);

    $publicPath = "$workspace/field-report.candidate.md";
    $internalPath = "$workspace/internal-synthesis.candidate.md";

    file_put_contents($publicPath, surveyRenderPublicCandidate($synthesis));
    file_put_contents($internalPath, surveyRenderInternalCandidate($synthesis));

    echo "wrote {$publicPath}\n";
    echo "wrote {$internalPath}\n";
}
