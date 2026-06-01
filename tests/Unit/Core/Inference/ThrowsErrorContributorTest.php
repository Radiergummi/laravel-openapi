<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Core\ErrorContributors\ThrowsErrorContributor;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Tests\Fixtures\TeapotException;

uses()->group('openapi');

function throwsDescriptor(array $throws): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: null,
        method: null,
        summary: null,
        description: null,
        throws: $throws,
    );
}

// region Empty throws list

it('returns an empty list when throws is empty', function (): void {
    $contributor = new ThrowsErrorContributor(
        findings: new ArrayFindingsCollector(),
    );

    $result = $contributor->contribute(throwsDescriptor([]));

    expect($result)->toBe([]);
});

it('emits no findings when throws is empty', function (): void {
    $collector = new ArrayFindingsCollector();
    $contributor = new ThrowsErrorContributor(findings: $collector);

    $contributor->contribute(throwsDescriptor([]));

    expect($collector->all())->toBe([]);
});

// endregion

// region Mapped via config

it('returns an ErrorDescriptor for an exception mapped in config', function (): void {
    $contributor = new ThrowsErrorContributor(
        findings: new ArrayFindingsCollector(),
        exceptionMap: [
            RuntimeException::class => ['status' => 500, 'description' => 'Server error'],
        ],
    );

    $result = $contributor->contribute(throwsDescriptor([RuntimeException::class]));

    expect($result)->toHaveCount(1);
    expect($result[0]->status)->toBe(500);
    expect($result[0]->description)->toBe('Server error');
});

// endregion

// region Attribute wins over config

it('uses the #[ExceptionResponse] attribute even when config also maps the exception', function (): void {
    $contributor = new ThrowsErrorContributor(
        findings: new ArrayFindingsCollector(),
        exceptionMap: [
            TeapotException::class => ['status' => 500, 'description' => 'Config description'],
        ],
    );

    $result = $contributor->contribute(throwsDescriptor([TeapotException::class]));

    expect($result)->toHaveCount(1);
    expect($result[0]->status)->toBe(418);
    expect($result[0]->description)->toBe("I'm a teapot");
});

// endregion

// region Unmapped exception

it('returns an empty list and emits a throws.unmapped finding for an unmapped exception', function (): void {
    $collector = new ArrayFindingsCollector();
    $contributor = new ThrowsErrorContributor(
        findings: $collector,
        exceptionMap: [],
    );

    $result = $contributor->contribute(throwsDescriptor([RuntimeException::class]));

    expect($result)->toBe([]);
    expect($collector->all())->toHaveCount(1);
    expect($collector->all()[0]->ruleId)->toBe('throws.unmapped');
    expect($collector->all()[0]->message)->toContain(RuntimeException::class);
});

// endregion

// region exceptionClass populated for Throwable subclasses

it('populates exceptionClass when @throws is a real Throwable subclass', function (): void {
    $contributor = new ThrowsErrorContributor(
        findings: new ArrayFindingsCollector(),
        exceptionMap: [
            RuntimeException::class => ['status' => 500, 'description' => 'Server error'],
        ],
    );

    $result = $contributor->contribute(throwsDescriptor([RuntimeException::class]));

    expect($result)->toHaveCount(1);
    expect($result[0]->exceptionClass)->toBe(RuntimeException::class);
});

it('populates exceptionClass when @throws references a Throwable interface', function (): void {
    $contributor = new ThrowsErrorContributor(
        findings: new ArrayFindingsCollector(),
        exceptionMap: [
            'Throwable' => ['status' => 500, 'description' => 'Server error'],
        ],
    );

    $result = $contributor->contribute(throwsDescriptor([Throwable::class]));

    expect($result)->toHaveCount(1);
    expect($result[0]->exceptionClass)->toBe(Throwable::class);
});

// endregion

// region Non-loadable class string

it('resolves the descriptor but sets exceptionClass to null for a non-loadable class mapped by exact string', function (): void {
    $contributor = new ThrowsErrorContributor(
        findings: new ArrayFindingsCollector(),
        exceptionMap: [
            'App\\Exceptions\\SomeUnloadableName' => ['status' => 503, 'description' => 'Unavailable'],
        ],
    );

    $result = $contributor->contribute(throwsDescriptor(['App\\Exceptions\\SomeUnloadableName']));

    expect($result)->toHaveCount(1);
    expect($result[0]->status)->toBe(503);
    expect($result[0]->description)->toBe('Unavailable');
    expect($result[0]->exceptionClass)->toBeNull();
});

// endregion
