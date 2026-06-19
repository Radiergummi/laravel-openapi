<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tools/survey/synthesize.php';

/** @return array<string, mixed> */
function surveyFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__ . "/../Fixtures/survey/{$name}"), true);
}

/** The frozen fixture trio the determinism + leak-guard tests run against. */
function surveyFixtureInputs(): array
{
    return [
        surveyFixture('results.json'),
        surveyFixture('manifest.json'),
        [surveyFixture('lift-Alpha.json'), surveyFixture('lift-Gamma.json')],
    ];
}

it('produces a byte-identical record and Markdown across two calls on the same inputs', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $first = surveySynthesize($results, $manifest, $lifts);
    $second = surveySynthesize($results, $manifest, $lifts);

    expect($first)->toBe($second);
    expect(surveyRenderPublicCandidate($first))->toBe(surveyRenderPublicCandidate($second));
    expect(surveyRenderInternalCandidate($first))->toBe(surveyRenderInternalCandidate($second));
});

it('renders byte-for-byte against the frozen golden snapshots (no wall-clock drift)', function (): void {
    // The two-calls-equal check above cannot catch a wall-clock leak when both calls land in the
    // same second; pinning the output to a committed golden file does. Any date()/time() in the
    // pure layer would shift these bytes and fail here. After an intentional render change,
    // regenerate the two expected-*.md fixtures from these inputs and review the diff.
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);

    expect(surveyRenderPublicCandidate($synthesis))
        ->toBe((string) file_get_contents(__DIR__ . '/../Fixtures/survey/expected-public-candidate.md'));
    expect(surveyRenderInternalCandidate($synthesis))
        ->toBe((string) file_get_contents(__DIR__ . '/../Fixtures/survey/expected-internal-candidate.md'));
});

it('classifies each app on the response spectrum from its metrics', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);
    $byName = [];

    foreach ($synthesis['apps'] as $app) {
        $byName[$app['name']] = $app['classification'];
    }

    // Alpha: 27/30 substantive -> full-schema. Beta: documentedResponses 19 >> responseSchemas 3
    // -> envelope-empty. Gamma: 0 responseSchemas -> no-body. Delta: blocked-compat -> blocked
    // (never mislabeled no-body even though its responseSchemas is 0).
    expect($byName['Alpha'])->toBe('full-schema')
        ->and($byName['Beta'])->toBe('envelope-empty')
        ->and($byName['Gamma'])->toBe('no-body')
        ->and($byName['Delta'])->toBe('blocked');
});

it('orders apps in corpus (results) order and surfaces the raw ratios behind each label', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);

    expect(array_column($synthesis['apps'], 'name'))->toBe(['Alpha', 'Beta', 'Gamma', 'Delta', 'Orphan']);

    $alpha = $synthesis['apps'][0];
    expect($alpha['ratios']['responseSchemas'])->toBe(27)
        ->and($alpha['ratios']['apiOperations'])->toBe(30);
});

it('rolls up corpus totals and clean-run counts from results.json', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);
    $corpus = $synthesis['corpus'];

    // 5 apps; only Orphan generated AND linted at exit 0 -> 1 clean run; Delta crashed.
    expect($corpus['appCount'])->toBe(5)
        ->and($corpus['cleanRuns'])->toBe(1)
        ->and($corpus['totalApiOperations'])->toBe(30 + 20 + 12 + 0 + 5)
        ->and($corpus['totalResponseSchemas'])->toBe(27 + 3 + 0 + 0 + 4)
        ->and($corpus['totalRequestBodies'])->toBe(12 + 6 + 1 + 0 + 1);
});

it('ranks recurring lint rules by count DESC then rule-ID ASC, proving the tie-break', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);
    $byRule = $synthesis['recurringGaps']['byRule'];

    // Corpus byRule totals: response.no-error = 4 (Alpha 2 + Beta 2);
    // operation.summary-missing = 2, response.success-empty-body = 2 (TIED at 2);
    // operation.return-type-missing = 1. The tie at 2 must break on rule-ID ASC:
    // operation.summary-missing < response.success-empty-body.
    expect($byRule[0])->toBe(['rule' => 'response.no-error', 'count' => 4])
        ->and($byRule[1])->toBe(['rule' => 'operation.summary-missing', 'count' => 2])
        ->and($byRule[2])->toBe(['rule' => 'response.success-empty-body', 'count' => 2])
        ->and($byRule[3])->toBe(['rule' => 'operation.return-type-missing', 'count' => 1]);
});

it('dedupes the B1 gap inventory across apps by title and records which apps hit each', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);
    $inventory = $synthesis['recurringGaps']['inventory'];
    $byTitle = [];

    foreach ($inventory as $gap) {
        $byTitle[$gap['title']] = $gap['apps'];
    }

    // The polymorphic-union gap is hit by both Alpha and Gamma -> one deduped entry, two apps.
    expect($byTitle['No attribute for polymorphic union responses'])->toBe(['Alpha', 'Gamma']);
    // The two single-app gaps each appear once.
    expect($byTitle)->toHaveKey('Cursor pagination meta block not derivable')
        ->and($byTitle)->toHaveKey('Streamed CSV download response not describable');

    // Total order: count DESC then title ASC. The 2-app gap leads.
    expect($inventory[0]['title'])->toBe('No attribute for polymorphic union responses');
});

it('includes the per-app annotation-lift breakdown only for apps with a lift.json', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);
    $liftApps = array_column($synthesis['lift'], 'app');

    // Alpha + Gamma have lift.json; Beta/Delta/Orphan do not -> omitted, no error.
    expect($liftApps)->toBe(['Alpha', 'Gamma']);

    $alphaLift = $synthesis['lift'][0];
    expect($alphaLift['baseline']['completenessPercent'])->toBe(90.0)
        ->and($alphaLift['afterAgent']['completenessPercent'])->toBe(100.0)
        ->and($alphaLift['harvested'])->toBe(14)
        ->and($alphaLift['authored'])->toBe(3);
});

it('treats an empty lift set as no lift measured, not an error', function (): void {
    [$results, $manifest] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, []);

    expect($synthesis['lift'])->toBe([]);
    // The recurring-gap inventory comes only from lifts, so it is empty too.
    expect($synthesis['recurringGaps']['inventory'])->toBe([]);
    // The internal candidate still renders, with an empty lift section.
    $internal = surveyRenderInternalCandidate($synthesis);
    expect($internal)->toContain('Annotation lift');
});

it('tolerates an app in results that is missing from the manifest, and vice versa', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);

    // Orphan is in results.json but not manifest.json -> still a corpus app, no provenance row.
    expect(array_column($synthesis['apps'], 'name'))->toContain('Orphan');
    $provenanceNames = array_column($synthesis['provenance']['apps'], 'name');
    expect($provenanceNames)->not->toContain('Orphan');

    // Phantom is in manifest.json but not results.json -> carried in provenance, not in the
    // measured-apps list (we have no metrics for it).
    expect($provenanceNames)->toContain('Phantom');
    expect(array_column($synthesis['apps'], 'name'))->not->toContain('Phantom');
});

it('keeps the coverage block only for apps whose metrics carry one', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);
    $byName = [];

    foreach ($synthesis['apps'] as $app) {
        $byName[$app['name']] = $app;
    }

    // Only Alpha pinned a publishedSpec -> only Alpha carries coverage.
    expect($byName['Alpha'])->toHaveKey('coverage')
        ->and($byName['Alpha']['coverage']['covPercent'])->toBe(87.5)
        ->and($byName['Beta'])->not->toHaveKey('coverage')
        ->and($byName['Gamma'])->not->toHaveKey('coverage');
});

it('keeps published-spec coverage and competitor framing out of the public candidate (leak guard)', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);
    $public = surveyRenderPublicCandidate($synthesis);

    // The publication gate banner must be present so the candidate can't ship without clearing #159.
    expect($public)->toContain('PUBLICATION GATE')
        ->and($public)->toContain('#159');

    // No published-spec/coverage wording leaks into the public artifact.
    expect($public)->not->toContain('covPercent')
        ->and($public)->not->toContain('coverage')
        ->and($public)->not->toContain('publishedOps')
        ->and(strtolower($public))->not->toContain('competitor');
});

it('carries the coverage block and gap inventory in the internal candidate (leak guard, other direction)', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);
    $internal = surveyRenderInternalCandidate($synthesis);

    // The internal candidate frames coverage as self-comparison, never a third-party benchmark.
    expect($internal)->toContain('published spec')
        ->and($internal)->toContain('87.5')
        ->and(strtolower($internal))->not->toContain('competitor');

    // It carries the recurring-gap rollup and the deduped B1 gap inventory.
    expect($internal)->toContain('response.no-error')
        ->and($internal)->toContain('No attribute for polymorphic union responses');
});

it('writes only the two candidate files into the workspace, never the curated docs', function (): void {
    // The CLI tail must write exclusively to distinct $WS candidate paths and never touch the
    // hand-authored docs/field-report.md or docs/internal/** (the #159-gated editorial targets).
    $workspace = sys_get_temp_dir() . '/survey-synth-' . bin2hex(random_bytes(6));
    mkdir($workspace . '/apps/Alpha', 0o777, true);
    mkdir($workspace . '/apps/Gamma', 0o777, true);

    copy(__DIR__ . '/../Fixtures/survey/results.json', $workspace . '/results.json');
    copy(__DIR__ . '/../Fixtures/survey/manifest.json', $workspace . '/manifest.json');
    copy(__DIR__ . '/../Fixtures/survey/lift-Alpha.json', $workspace . '/apps/Alpha/lift.json');
    copy(__DIR__ . '/../Fixtures/survey/lift-Gamma.json', $workspace . '/apps/Gamma/lift.json');

    $script = __DIR__ . '/../../tools/survey/synthesize.php';
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($workspace) . ' 2>&1', $stdout, $exit);

    expect($exit)->toBe(0);

    // Exactly the two candidate files were created beyond the inputs we copied in.
    $written = array_values(array_filter(
        scandir($workspace),
        static fn(string $f): bool => str_ends_with($f, '.candidate.md'),
    ));
    sort($written);

    expect($written)->toBe(['field-report.candidate.md', 'internal-synthesis.candidate.md']);
    expect(is_file($workspace . '/field-report.candidate.md'))->toBeTrue()
        ->and(is_file($workspace . '/internal-synthesis.candidate.md'))->toBeTrue();

    // The repo's curated targets are untouched: their names never appear in the write set, and the
    // emitter writes only under $WS (no path traversal into docs/).
    expect($written)->not->toContain('field-report.md');

    // The emitted public candidate matches the pure renderer byte-for-byte (no I/O-layer drift).
    expect((string) file_get_contents($workspace . '/field-report.candidate.md'))
        ->toBe((string) file_get_contents(__DIR__ . '/../Fixtures/survey/expected-public-candidate.md'));

    array_map('unlink', array_filter(glob($workspace . '/apps/*/lift.json') ?: [], 'is_file'));
    array_map('unlink', array_filter(glob($workspace . '/*') ?: [], 'is_file'));
    rmdir($workspace . '/apps/Alpha');
    rmdir($workspace . '/apps/Gamma');
    rmdir($workspace . '/apps');
    rmdir($workspace);
});

it('embeds the provenance manifest verbatim in both candidates', function (): void {
    [$results, $manifest, $lifts] = surveyFixtureInputs();

    $synthesis = surveySynthesize($results, $manifest, $lifts);
    $public = surveyRenderPublicCandidate($synthesis);
    $internal = surveyRenderInternalCandidate($synthesis);

    foreach ([$public, $internal] as $candidate) {
        expect($candidate)->toContain('abc1234def5678abc1234def5678abc1234def56') // libraryCommit
            ->and($candidate)->toContain('2026-06-18T09:30:00+00:00')             // generatedAt
            ->and($candidate)->toContain('1111111111111111111111111111111111111111'); // Alpha pinnedSha
    }
});
