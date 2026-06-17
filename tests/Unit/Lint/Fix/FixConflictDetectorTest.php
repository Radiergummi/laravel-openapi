<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Fix\Ast\AddAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\AstOperation;
use Radiergummi\OpenApi\Lint\Fix\Ast\FixConflictDetector;
use Radiergummi\OpenApi\Lint\Fix\Ast\RemoveAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\SetAttributeArgument;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixSkipReason;

uses()->group('openapi', 'lint', 'fix');

function detectorFix(AstOperation $operation, string $file = '/app/Controller.php'): Fix
{
    return new Fix($file, 'desc', 'rule.id', $operation);
}

function methodTarget(string $member = 'index', string $class = 'App\\Controller'): TargetSelector
{
    return new TargetSelector($class, TargetKind::Method, $member);
}

it('keeps the first and skips the second of two SetAttributeArgument on the same attribute+argument', function (): void {
    $first = detectorFix(new SetAttributeArgument(methodTarget(), 0, 'operationId', 'a'));
    $second = detectorFix(new SetAttributeArgument(methodTarget(), 0, 'operationId', 'b'));

    $result = new FixConflictDetector()->partition([$first, $second]);

    expect($result['kept'])->toBe([$first])
        ->and($result['skipped'])->toHaveCount(1)
        ->and($result['skipped'][0]->fix)->toBe($second)
        ->and($result['skipped'][0]->reason)->toBe(FixSkipReason::Conflict);
});

it('keeps two SetAttributeArgument on the same attribute but different arguments', function (): void {
    $first = detectorFix(new SetAttributeArgument(methodTarget(), 0, 'operationId', 'a'));
    $second = detectorFix(new SetAttributeArgument(methodTarget(), 0, 'summary', 'b'));

    $result = new FixConflictDetector()->partition([$first, $second]);

    expect($result['kept'])->toBe([$first, $second])
        ->and($result['skipped'])->toBe([]);
});

it('skips a SetAttributeArgument that depends on an index a kept RemoveAttribute drops', function (): void {
    $remove = detectorFix(new RemoveAttribute(methodTarget(), [0]));
    $set = detectorFix(new SetAttributeArgument(methodTarget(), 0, 'operationId', 'a'));

    $result = new FixConflictDetector()->partition([$remove, $set]);

    expect($result['kept'])->toBe([$remove])
        ->and($result['skipped'][0]->fix)->toBe($set)
        ->and($result['skipped'][0]->reason)->toBe(FixSkipReason::Conflict);
});

it('skips a SetAttributeArgument even on an index a kept RemoveAttribute does not name', function (): void {
    // A removal re-indexes the member's flat attribute list mid-traversal, so a later op's index is
    // no longer trustworthy regardless of which indices the removal named. The set is skipped.
    $remove = detectorFix(new RemoveAttribute(methodTarget(), [1]));
    $set = detectorFix(new SetAttributeArgument(methodTarget(), 0, 'operationId', 'a'));

    $result = new FixConflictDetector()->partition([$remove, $set]);

    expect($result['kept'])->toBe([$remove])
        ->and($result['skipped'][0]->fix)->toBe($set)
        ->and($result['skipped'][0]->reason)->toBe(FixSkipReason::Conflict);
});

it('skips the second of two RemoveAttribute on one member even with disjoint indices', function (): void {
    // Disjoint indices are not independent across separate ops: the first removal compacts the list,
    // so the second op's original indices then address shifted attributes. Keep first, skip the rest.
    $first = detectorFix(new RemoveAttribute(methodTarget(), [0, 1]));
    $second = detectorFix(new RemoveAttribute(methodTarget(), [2, 3]));

    $result = new FixConflictDetector()->partition([$first, $second]);

    expect($result['kept'])->toBe([$first])
        ->and($result['skipped'][0]->fix)->toBe($second)
        ->and($result['skipped'][0]->reason)->toBe(FixSkipReason::Conflict);
});

it('treats an AddAttribute as conflicting with any other op on the same node', function (): void {
    $add = detectorFix(new AddAttribute(methodTarget(), 'App\\Attr', ['x' => 'y']));
    $set = detectorFix(new SetAttributeArgument(methodTarget(), 0, 'operationId', 'a'));

    $result = new FixConflictDetector()->partition([$add, $set]);

    expect($result['kept'])->toBe([$add])
        ->and($result['skipped'][0]->fix)->toBe($set);
});

it('keeps ops on different members of the same class', function (): void {
    $a = detectorFix(new SetAttributeArgument(methodTarget('show'), 0, 'operationId', 'a'));
    $b = detectorFix(new SetAttributeArgument(methodTarget('store'), 0, 'operationId', 'b'));

    $result = new FixConflictDetector()->partition([$a, $b]);

    expect($result['kept'])->toBe([$a, $b])
        ->and($result['skipped'])->toBe([]);
});

it('keeps ops on the same member name but different classes', function (): void {
    $a = detectorFix(new SetAttributeArgument(methodTarget('index', 'App\\A'), 0, 'operationId', 'a'));
    $b = detectorFix(new SetAttributeArgument(methodTarget('index', 'App\\B'), 0, 'operationId', 'b'));

    $result = new FixConflictDetector()->partition([$a, $b]);

    expect($result['kept'])->toBe([$a, $b]);
});

it('partitions deterministically: first-in-list always wins', function (): void {
    $first = detectorFix(new SetAttributeArgument(methodTarget(), 0, 'operationId', 'a'));
    $second = detectorFix(new SetAttributeArgument(methodTarget(), 0, 'operationId', 'b'));
    $third = detectorFix(new SetAttributeArgument(methodTarget(), 0, 'operationId', 'c'));

    $result = new FixConflictDetector()->partition([$first, $second, $third]);

    expect($result['kept'])->toBe([$first])
        ->and($result['skipped'])->toHaveCount(2)
        ->and($result['skipped'][0]->fix)->toBe($second)
        ->and($result['skipped'][1]->fix)->toBe($third);
});
