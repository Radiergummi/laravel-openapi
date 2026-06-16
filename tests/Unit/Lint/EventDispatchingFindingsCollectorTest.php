<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Lint\EventDispatchingFindingsCollector;
use Radiergummi\OpenApi\Lint\Finding;

uses()->group('openapi');

it('still collects the finding when a listener throws, and logs the failure', function (): void {
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('hasListeners')->andReturnTrue();
    $events->shouldReceive('dispatch')->andThrow(new RuntimeException('listener boom'));

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once();

    $inner = new ArrayFindingsCollector();
    $collector = new EventDispatchingFindingsCollector($inner, $events, $logger);

    $collector->emit(new Finding('rule.id', Severity::Broken, 'message'));

    expect($inner->all())->toHaveCount(1);
});

it('does not dispatch (or log) when no listener is registered', function (): void {
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('hasListeners')->andReturnFalse();
    $events->shouldNotReceive('dispatch');

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldNotReceive('warning');

    $inner = new ArrayFindingsCollector();
    $collector = new EventDispatchingFindingsCollector($inner, $events, $logger);

    $collector->emit(new Finding('rule.id', Severity::Broken, 'message'));

    expect($inner->all())->toHaveCount(1);
});
