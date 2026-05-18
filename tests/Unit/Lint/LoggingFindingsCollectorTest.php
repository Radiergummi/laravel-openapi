<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\LoggingFindingsCollector;

uses()->group('openapi', 'lint');

it('logs level-0 (error) findings at PSR-3 error with structured context', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')
        ->once()
        ->with(
            '[OpenAPI] response.empty: No response schema for GET foo',
            Mockery::on(static function (array $ctx): bool {
                return $ctx['rule_id'] === 'response.empty'
                    && $ctx['level'] === 0
                    && $ctx['route'] === 'foo.show'
                    && $ctx['file'] === 'Foo.php'
                    && $ctx['line'] === 10
                    && $ctx['fix'] === 'Add #[Response].';
            }),
        );

    $collector = new LoggingFindingsCollector($logger);

    $collector->emit(new Finding(
        ruleId: 'response.empty',
        level: 0,
        message: 'No response schema for GET foo',
        location: new FindingLocation(
            file: 'Foo.php',
            line: 10,
            routeName: 'foo.show',
        ),
        fixHint: 'Add #[Response].',
    ));
});

it('logs level-1 (warning) findings at PSR-3 warning', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->once()
        ->with(
            '[OpenAPI] some.rule: Something fishy',
            Mockery::any(),
        );

    (new LoggingFindingsCollector($logger))->emit(new Finding(
        ruleId: 'some.rule',
        level: 1,
        message: 'Something fishy',
    ));
});

it('logs level-2 (notice/info) findings at PSR-3 info', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')
        ->once()
        ->with(
            '[OpenAPI] some.rule: Just so you know',
            Mockery::any(),
        );

    (new LoggingFindingsCollector($logger))->emit(new Finding(
        ruleId: 'some.rule',
        level: 2,
        message: 'Just so you know',
    ));
});
