<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Registry\ResolverFaultBoundary;

uses()->group('openapi');

// region Helpers

function faultBoundaryDescriptor(string $uri = 'widgets/{id}'): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route('GET', $uri, []),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );
}

// endregion

// region Success passthrough

it('returns the callable result when nothing is thrown', function (): void {
    $logger   = recordingLogger();
    $boundary = new ResolverFaultBoundary($logger);

    $result = $boundary->isolate('SomeResolver', faultBoundaryDescriptor(), fn(): string => 'ok');

    expect($result)->toBe('ok')
        ->and($logger->records)->toBe([]);
});

it('passes through a null result without logging', function (): void {
    $logger   = recordingLogger();
    $boundary = new ResolverFaultBoundary($logger);

    $result = $boundary->isolate('SomeResolver', faultBoundaryDescriptor(), fn(): ?string => null);

    expect($result)->toBeNull()
        ->and($logger->records)->toBe([]);
});

// endregion

// region Exception isolation

it('returns null and logs a warning with route + resolver context when an Exception is thrown', function (): void {
    $logger   = recordingLogger();
    $boundary = new ResolverFaultBoundary($logger);

    $result = $boundary->isolate(
        'My\\Failing\\Resolver',
        faultBoundaryDescriptor('orders/{order}'),
        fn(): string => throw new RuntimeException('boom'),
    );

    expect($result)->toBeNull()
        ->and($logger->records)->toHaveCount(1);

    $record = $logger->records[0];

    expect($record['level'])->toBe('warning')
        ->and($record['message'])->toContain('My\\Failing\\Resolver')
        ->and($record['message'])->toContain('orders/{order}')
        ->and($record['message'])->toContain('boom');
});

it('isolates a ReflectionException', function (): void {
    $logger   = recordingLogger();
    $boundary = new ResolverFaultBoundary($logger);

    $result = $boundary->isolate(
        'R',
        faultBoundaryDescriptor(),
        fn(): string => throw new ReflectionException('missing'),
    );

    expect($result)->toBeNull()
        ->and($logger->records)->toHaveCount(1);
});

// endregion

// region Programming errors propagate

it('lets a TypeError propagate instead of swallowing it', function (): void {
    $logger   = recordingLogger();
    $boundary = new ResolverFaultBoundary($logger);

    expect(fn(): mixed => $boundary->isolate(
        'R',
        faultBoundaryDescriptor(),
        fn(): string => throw new TypeError('logic bug'),
    ))->toThrow(TypeError::class);

    expect($logger->records)->toBe([]);
});

it('lets a generic Error propagate', function (): void {
    $logger   = recordingLogger();
    $boundary = new ResolverFaultBoundary($logger);

    expect(fn(): mixed => $boundary->isolate(
        'R',
        faultBoundaryDescriptor(),
        fn(): string => throw new Error('fatal'),
    ))->toThrow(Error::class);
});

// endregion
