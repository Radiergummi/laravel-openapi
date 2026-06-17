<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Ast\RemoveAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixResult;
use Radiergummi\OpenApi\Lint\Fix\FixRunResult;
use Radiergummi\OpenApi\Lint\Fix\FixSkipReason;
use Radiergummi\OpenApi\Lint\Fix\SkippedFix;

uses()->group('openapi', 'lint', 'fix');

function runResultFix(string $ruleId, string $file): Fix
{
    return new Fix(
        $file,
        'desc',
        $ruleId,
        new RemoveAttribute(new TargetSelector('App\\C', TargetKind::Method, 'index'), [0]),
    );
}

function runResultFinding(string $ruleId): Finding
{
    return new Finding(ruleId: $ruleId, severity: Severity::Degraded, message: 'msg');
}

it('serializes the frozen fix-run envelope with the exact key set', function (): void {
    $applied = [runResultFix('a.applied', '/app/A.php')];
    $skipped = [new SkippedFix(runResultFix('b.skipped', '/app/B.php'), FixSkipReason::Conflict)];
    $remaining = [runResultFinding('c.remaining')];

    $outcome = new FixRunResult(
        fixResult: new FixResult($applied, $skipped, ['/app/A.php']),
        remainingFindings: $remaining,
        level: 2,
        dryRun: false,
    );

    $array = $outcome->toArray();

    expect($array)->toHaveKeys([
        'schema_version', 'mode', 'applied', 'skipped', 'withheld_destructive', 'modified_files', 'remaining', 'exit_code',
    ])
        ->and(array_keys($array))->toBe([
            'schema_version', 'mode', 'applied', 'skipped', 'withheld_destructive', 'modified_files', 'remaining', 'exit_code',
        ])
        ->and($array['schema_version'])->toBe('1')
        ->and($array['mode'])->toBe('fix')
        ->and($array['applied'])->toBe(1)
        ->and($array['skipped'])->toBe([
            ['rule_id' => 'b.skipped', 'file' => '/app/B.php', 'reason' => 'conflict'],
        ])
        ->and($array['withheld_destructive'])->toBe(0)
        ->and($array['modified_files'])->toBe(['/app/A.php'])
        ->and($array['remaining'])->toBe($remaining)
        ->and($array['exit_code'])->toBe(1);
});

it('reports mode=check and a clean exit when nothing would be fixed', function (): void {
    $outcome = new FixRunResult(
        fixResult: new FixResult([], [], []),
        remainingFindings: [],
        level: 2,
        dryRun: true,
    );

    $array = $outcome->toArray();

    expect($array['mode'])->toBe('check')
        ->and($array['applied'])->toBe(0)
        ->and($array['skipped'])->toBe([])
        ->and($array['modified_files'])->toBe([])
        ->and($array['remaining'])->toBe([])
        ->and($array['exit_code'])->toBe(0);
});

it('reports an all-skipped run: zero applied, skipped entries, finding stays remaining', function (): void {
    $skipped = [
        new SkippedFix(runResultFix('x', '/app/X.php'), FixSkipReason::Conflict),
        new SkippedFix(runResultFix('x', '/app/X.php'), FixSkipReason::NodeNotFound),
    ];

    $outcome = new FixRunResult(
        fixResult: new FixResult([], $skipped, []),
        remainingFindings: [runResultFinding('x')],
        level: 2,
        dryRun: false,
    );

    $array = $outcome->toArray();

    expect($array['applied'])->toBe(0)
        ->and($array['skipped'])->toHaveCount(2)
        ->and($array['skipped'][1]['reason'])->toBe('node-not-found')
        ->and($array['exit_code'])->toBe(1);
});

it('is JSON-encodable to a stable shape (remaining reuses Finding json shape, not the lint findings key)', function (): void {
    $outcome = new FixRunResult(
        fixResult: new FixResult([], [], []),
        remainingFindings: [runResultFinding('c.remaining')],
        level: 2,
        dryRun: false,
    );

    $decoded = json_decode(json_encode($outcome->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->not->toHaveKey('findings')
        ->and($decoded['remaining'][0])->toHaveKeys(['rule_id', 'level', 'message']);
});
