<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Radiergummi\OpenApi\Support\MethodBody\VariableRebinding;

uses()->group('openapi');

/**
 * Whether any node in the snippet rebinds `$subject`, mirroring how the readers hand the predicate
 * to a node finder that descends into conditional bodies.
 */
function rebindsSubject(string $code): bool
{
    $statements = new ParserFactory()->createForNewestSupportedVersion()->parse("<?php {$code}");

    assert($statements !== null);

    return new NodeFinder()->findFirst(
        $statements,
        static fn(Node $node): bool => VariableRebinding::matches($node, 'subject'),
    ) !== null;
}

it('matches a plain assignment', function (): void {
    expect(rebindsSubject('$subject = $other;'))->toBeTrue();
});

it('matches array destructuring', function (): void {
    expect(rebindsSubject('[$subject, $other] = $pair;'))->toBeTrue();
});

it('matches list() destructuring', function (): void {
    expect(rebindsSubject('list($subject) = $pair;'))->toBeTrue();
});

it('matches keyed destructuring', function (): void {
    expect(rebindsSubject("['tenant' => \$subject] = \$row;"))->toBeTrue();
});

it('matches a reference assignment to the name', function (): void {
    expect(rebindsSubject('$subject = &$other;'))->toBeTrue();
});

it('matches a reference assignment aliasing the name', function (): void {
    expect(rebindsSubject('$other = &$subject;'))->toBeTrue();
});

it('matches a compound assignment', function (): void {
    expect(rebindsSubject('$subject .= "x";'))->toBeTrue()
        ->and(rebindsSubject('$subject += 1;'))->toBeTrue();
});

it('matches increment and decrement in both positions', function (): void {
    expect(rebindsSubject('$subject++;'))->toBeTrue()
        ->and(rebindsSubject('++$subject;'))->toBeTrue()
        ->and(rebindsSubject('$subject--;'))->toBeTrue()
        ->and(rebindsSubject('--$subject;'))->toBeTrue();
});

it('matches a foreach value target', function (): void {
    expect(rebindsSubject('foreach ($others as $subject) {}'))->toBeTrue();
});

it('matches a foreach key target', function (): void {
    expect(rebindsSubject('foreach ($others as $subject => $value) {}'))->toBeTrue();
});

it('matches a foreach destructuring value target', function (): void {
    expect(rebindsSubject('foreach ($others as [$subject, $value]) {}'))->toBeTrue();
});

it('matches a catch capture', function (): void {
    expect(rebindsSubject('try {} catch (Throwable $subject) {}'))->toBeTrue();
});

it('matches static, global, and unset statements', function (): void {
    expect(rebindsSubject('static $subject;'))->toBeTrue()
        ->and(rebindsSubject('global $subject;'))->toBeTrue()
        ->and(rebindsSubject('unset($subject);'))->toBeTrue();
});

it('matches a rebinding nested inside a conditional body', function (): void {
    expect(rebindsSubject('if ($flag) { $subject = $other; }'))->toBeTrue();
});

it('does not match writes to a different name', function (): void {
    expect(rebindsSubject('$other = $subject;'))->toBeFalse()
        ->and(rebindsSubject('$other++;'))->toBeFalse()
        ->and(rebindsSubject('foreach ($subjects as $other) {}'))->toBeFalse()
        ->and(rebindsSubject('unset($other);'))->toBeFalse();
});

it('does not match a read of the name', function (): void {
    expect(rebindsSubject('$subject->save();'))->toBeFalse()
        ->and(rebindsSubject('return $subject->toResource();'))->toBeFalse();
});

it('does not match a dynamically-named variable', function (): void {
    expect(rebindsSubject('$$name = $other;'))->toBeFalse();
});
