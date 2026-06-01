<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixApplicator;
use Radiergummi\OpenApi\Lint\Fix\FixOperation;
use Radiergummi\OpenApi\Lint\Fix\ModifyAttribute;
use Radiergummi\OpenApi\Lint\Fix\RemoveLines;
use Radiergummi\OpenApi\Lint\Fix\ReplaceLines;

uses()->group('openapi', 'lint', 'fix');

function fixtureFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'openapi-fix-test-') ?: '';
    file_put_contents($path, $contents);

    return $path;
}

function makeFix(string $file, FixOperation $operation, string $ruleId = 'test.rule'): Fix
{
    return new Fix($file, 'description', $ruleId, $operation);
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/openapi-fix-test-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('removes a whole line and leaves the rest byte-identical', function (): void {
    $file = fixtureFile("line1\nline2\nline3\n");

    $result = new FixApplicator()->apply([
        makeFix($file, new RemoveLines(2, 2)),
    ]);

    expect(file_get_contents($file))->toBe("line1\nline3\n")
        ->and($result->applied)->toHaveCount(1)
        ->and($result->skipped)->toBe([])
        ->and($result->modifiedFiles)->toBe([$file])
        ->and($result->hasChanges())->toBeTrue();
});

it('applies multiple non-overlapping removals bottom-to-top without offset drift', function (): void {
    $file = fixtureFile("a\nb\nc\nd\ne\n");

    new FixApplicator()->apply([
        makeFix($file, new RemoveLines(2, 2)),
        makeFix($file, new RemoveLines(4, 4)),
    ]);

    // Removing lines 2 and 4 (b and d) regardless of the order they are supplied.
    expect(file_get_contents($file))->toBe("a\nc\ne\n");
});

it('skips a later fix that overlaps an already-applied one', function (): void {
    $file = fixtureFile("a\nb\nc\nd\n");

    $result = new FixApplicator()->apply([
        makeFix($file, new RemoveLines(2, 3)),
        makeFix($file, new ReplaceLines(3, 3, 'X')),
    ]);

    // Bottom-to-top: the line-3 replace (higher offset) is applied first; the overlapping
    // 2–3 removal that follows is skipped rather than guessed at.
    expect(file_get_contents($file))->toBe("a\nb\nX\nd\n")
        ->and($result->applied)->toHaveCount(1)
        ->and($result->applied[0]->operation)->toBeInstanceOf(ReplaceLines::class)
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->operation)->toBeInstanceOf(RemoveLines::class);
});

it('writes nothing in dry-run but still reports the pending change', function (): void {
    $file = fixtureFile("keep\ndrop\n");

    $result = new FixApplicator()->apply([
        makeFix($file, new RemoveLines(2, 2)),
    ], dryRun: true);

    expect(file_get_contents($file))->toBe("keep\ndrop\n")
        ->and($result->hasChanges())->toBeTrue()
        ->and($result->modifiedFiles)->toBe([$file]);
});

it('removes one attribute from a shared group via byte-precise ModifyAttribute', function (): void {
    $source = "#[Tag('a'), Tag('a')]\npublic function x() {}\n";
    $file = fixtureFile($source);

    // Replace the byte span of ", Tag('a')" (the second attribute) with nothing.
    $start = strpos($source, ", Tag('a')");

    if ($start === false) {
        throw new RuntimeException('fixture marker not found');
    }

    $end = $start + strlen(", Tag('a')");

    new FixApplicator()->apply([
        makeFix($file, new ModifyAttribute($start, $end, '')),
    ]);

    expect(file_get_contents($file))->toBe("#[Tag('a')]\npublic function x() {}\n");
});
