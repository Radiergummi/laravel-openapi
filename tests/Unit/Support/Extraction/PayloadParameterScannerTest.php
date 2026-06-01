<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Spatie\LaravelData\Data;

uses()->group('openapi');

// region Small fixture classes — no framework dependencies needed

/**
 * A generic indirection wrapper (stand-in for a Domain Action base class).
 *
 * @internal
 */
abstract class ScannerFixtureIndirectionBase {}

/**
 * Concrete indirection wrapper whose constructor carries a Data payload.
 *
 * @internal
 */
final class ScannerFixtureIndirectionWithData extends ScannerFixtureIndirectionBase
{
    public function __construct(
        public string $primitive,
        public ScannerFixturePayloadData $data,
    ) {}
}

/**
 * Concrete indirection wrapper with NO Data payload in its constructor.
 *
 * @internal
 */
final class ScannerFixtureIndirectionWithoutData extends ScannerFixtureIndirectionBase
{
    public function __construct(public string $name) {}
}

/**
 * A Spatie Data payload class used as a fixture.
 *
 * @internal
 */
final class ScannerFixturePayloadData extends Data
{
    public function __construct(public string $title) {}
}

/**
 * A second Data payload — used to verify ordering (direct param wins over indirection).
 *
 * @internal
 */
final class ScannerFixtureAnotherData extends Data
{
    public function __construct(public string $value) {}
}

/**
 * Controller fixture: method with a direct Data type-hint and no indirection param.
 *
 * @internal
 */
final class ScannerFixtureControllerDirectData
{
    public function store(ScannerFixturePayloadData $data): void {}
}

/**
 * Controller fixture: method with an indirection wrapper but no direct Data param.
 *
 * @internal
 */
final class ScannerFixtureControllerIndirection
{
    public function store(ScannerFixtureIndirectionWithData $action): void {}
}

/**
 * Controller fixture: method with BOTH a direct Data param AND an indirection wrapper.
 * Direct param should come first in candidates().
 *
 * @internal
 */
final class ScannerFixtureControllerBoth
{
    public function store(ScannerFixtureAnotherData $direct, ScannerFixtureIndirectionWithData $action): void {}
}

/**
 * Controller fixture: method with no typed params.
 *
 * @internal
 */
final class ScannerFixtureControllerNoParams
{
    public function index(): void {}
}

/**
 * Controller fixture: method with only builtin-typed and untyped params.
 *
 * @internal
 */
final class ScannerFixtureControllerBuiltins
{
    public function index(string $id, int $page): void {}
}

/**
 * Controller fixture: indirection wrapper with no constructor.
 *
 * @internal
 */
final class ScannerFixtureIndirectionNoConstructor extends ScannerFixtureIndirectionBase {}

final class ScannerFixtureControllerNoConstructorAction
{
    public function store(ScannerFixtureIndirectionNoConstructor $action): void {}
}

// endregion

// region Tests

it('returns the class-string of a direct named-type parameter', function (): void {
    $scanner = new PayloadParameterScanner();
    $method  = new ReflectionMethod(ScannerFixtureControllerDirectData::class, 'store');

    $candidates = $scanner->candidates($method);

    expect($candidates)->toContain(ScannerFixturePayloadData::class);
});

it('returns an empty list when the method has no parameters', function (): void {
    $scanner = new PayloadParameterScanner();
    $method  = new ReflectionMethod(ScannerFixtureControllerNoParams::class, 'index');

    $candidates = $scanner->candidates($method);

    expect($candidates)->toBe([]);
});

it('ignores builtin-typed parameters', function (): void {
    $scanner = new PayloadParameterScanner();
    $method  = new ReflectionMethod(ScannerFixtureControllerBuiltins::class, 'index');

    $candidates = $scanner->candidates($method);

    expect($candidates)->toBe([]);
});

it('descends into an indirection class constructor when indirectionClasses is configured', function (): void {
    $scanner = new PayloadParameterScanner(indirectionClasses: [ScannerFixtureIndirectionBase::class]);
    $method  = new ReflectionMethod(ScannerFixtureControllerIndirection::class, 'store');

    $candidates = $scanner->candidates($method);

    expect($candidates)->toContain(ScannerFixturePayloadData::class);
});

it('does NOT descend into an indirection class when indirectionClasses is empty', function (): void {
    $scanner = new PayloadParameterScanner(indirectionClasses: []);
    $method  = new ReflectionMethod(ScannerFixtureControllerIndirection::class, 'store');

    $candidates = $scanner->candidates($method);

    // The indirection class itself should still appear as a direct candidate (it is a named type),
    // but the constructor params of that class must NOT be included.
    expect($candidates)->not->toContain(ScannerFixturePayloadData::class)
        ->and($candidates)->toContain(ScannerFixtureIndirectionWithData::class);
});

it('returns direct method params before indirection constructor params', function (): void {
    $scanner = new PayloadParameterScanner(indirectionClasses: [ScannerFixtureIndirectionBase::class]);
    $method  = new ReflectionMethod(ScannerFixtureControllerBoth::class, 'store');

    $candidates = $scanner->candidates($method);

    $directIndex     = array_search(ScannerFixtureAnotherData::class, $candidates, strict: true);
    $indirectionIndex = array_search(ScannerFixturePayloadData::class, $candidates, strict: true);

    expect($directIndex)->toBeLessThan($indirectionIndex);
});

it('includes the indirection class itself in the direct-params section', function (): void {
    $scanner = new PayloadParameterScanner(indirectionClasses: [ScannerFixtureIndirectionBase::class]);
    $method  = new ReflectionMethod(ScannerFixtureControllerIndirection::class, 'store');

    $candidates = $scanner->candidates($method);

    // The indirection class appears as a direct candidate AND its constructor params follow.
    expect($candidates)->toContain(ScannerFixtureIndirectionWithData::class)
        ->and($candidates)->toContain(ScannerFixturePayloadData::class);
});

it('handles an indirection class with no constructor gracefully', function (): void {
    $scanner = new PayloadParameterScanner(indirectionClasses: [ScannerFixtureIndirectionBase::class]);
    $method  = new ReflectionMethod(ScannerFixtureControllerNoConstructorAction::class, 'store');

    // Should not throw; the indirection class itself appears but no extra constructor params.
    $candidates = $scanner->candidates($method);

    expect($candidates)->toContain(ScannerFixtureIndirectionNoConstructor::class)
        ->and($candidates)->toHaveCount(1);
});

it('ignores builtin-typed constructor params on the indirection class', function (): void {
    $scanner = new PayloadParameterScanner(indirectionClasses: [ScannerFixtureIndirectionBase::class]);
    $method  = new ReflectionMethod(ScannerFixtureControllerIndirection::class, 'store');

    $candidates = $scanner->candidates($method);

    // The `string $primitive` param in ScannerFixtureIndirectionWithData must NOT appear.
    expect($candidates)->not->toContain('string');
});

// endregion
