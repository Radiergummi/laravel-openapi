<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Fix\Ast\RemoveAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixSafety;

uses()->group('openapi', 'lint', 'fix');

function safetyFix(?FixSafety $safety = null): Fix
{
    $operation = new RemoveAttribute(new TargetSelector('App\\C', TargetKind::Method, 'index'), [0]);

    return $safety === null
        ? new Fix('/app/C.php', 'desc', 'rule.id', $operation)
        : new Fix('/app/C.php', 'desc', 'rule.id', $operation, $safety);
}

it('defaults a Fix built without the safety argument to Safe', function (): void {
    // This default keeps every existing fixer (which constructs Fix positionally) unchanged.
    expect(safetyFix()->safety)->toBe(FixSafety::Safe);
});

it('carries an explicit Destructive safety when given', function (): void {
    expect(safetyFix(FixSafety::Destructive)->safety)->toBe(FixSafety::Destructive);
});
