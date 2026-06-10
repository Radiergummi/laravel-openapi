<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\AbortErrorContributor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\AbortFixtureController;
use Radiergummi\OpenApi\Tests\Fixtures\AbortImpostor\OrderController as AbortImpostorController;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses()->group('openapi');

// region Helpers

function abortContributor(?LoggerInterface $logger = null): AbortErrorContributor
{
    return new AbortErrorContributor(new MethodBodyScanner(), $logger ?? new NullLogger());
}

/**
 * @param class-string $controller
 */
function abortActionDescriptor(string $controller, string $method): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, $method),
        summary: null,
        description: null,
    );
}

// endregion

// region Whitelisted call shapes

it('emits a 403 for a plain abort(403)', function (): void {
    $result = abortContributor()->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'plainAbort'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(403)
        ->and($result[0]->description)->toBe('Forbidden')
        ->and($result[0]->exceptionClass)->toBe(HttpException::class);
});

it('uses the literal message as the response description', function (): void {
    $result = abortContributor()->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'abortWithMessage'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(404)
        ->and($result[0]->description)->toBe('Order not found')
        ->and($result[0]->exceptionClass)->toBe(NotFoundHttpException::class);
});

it('reads the status from argument 1 of abort_if and never analyses the condition', function (): void {
    $result = abortContributor()->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'abortIf'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(401)
        ->and($result[0]->description)->toBe('Sign in first');
});

it('reads the status from argument 1 of abort_unless', function (): void {
    $result = abortContributor()->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'abortUnless'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(403)
        ->and($result[0]->description)->toBe('Admins only');
});

it('finds an abort inside an if guard', function (): void {
    $result = abortContributor()->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'guardedAbort'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(401);
});

it('emits one descriptor per distinct abort call, in source order', function (): void {
    $result = abortContributor()->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'multipleStatuses'),
    );

    expect($result)->toHaveCount(3)
        ->and($result[0]->status)->toBe(401)
        ->and($result[1]->status)->toBe(403)
        ->and($result[2]->status)->toBe(404)
        ->and($result[2]->description)->toBe('Order not found');
});

it('resolves a class-constant status on an imported, aliased class', function (): void {
    $result = abortContributor()->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'classConstantStatus'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(403)
        ->and($result[0]->description)->toBe('Cannot update a user prospect.')
        ->and($result[0]->exceptionClass)->toBe(HttpException::class);
});

it('emits a literal 5xx abort as a server-error response', function (): void {
    $result = abortContributor()->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'serverErrorAbort'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(500)
        ->and($result[0]->description)->toBe('Upstream failure');
});

it('marks authored messages as non-shareable and default descriptions as shareable', function (): void {
    $contributor = abortContributor();

    $authored = $contributor->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'abortWithMessage'),
    );
    $defaulted = $contributor->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'plainAbort'),
    );

    expect($authored[0]->shareableDescription)->toBeFalse()
        ->and($defaulted[0]->shareableDescription)->toBeTrue();
});

it('binds the action onto every emitted descriptor', function (): void {
    $descriptor = abortActionDescriptor(AbortFixtureController::class, 'plainAbort');

    $result = abortContributor()->contribute($descriptor);

    expect($result[0]->action)->toBe($descriptor);
});

// endregion

// region Degradation: non-literal status

it('skips a non-literal status and logs a generation note', function (): void {
    $logger = recordingLogger();

    $result = abortContributor($logger)->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'dynamicStatus'),
    );

    expect($result)->toBe([]);

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => $record['level'] === 'notice'
            && str_contains($record['message'], 'dynamicStatus')
            && str_contains($record['message'], 'abort'),
    );

    expect($noted)->toBeTrue();
});

it('skips an abort() carrying a Response object and logs a generation note', function (): void {
    $logger = recordingLogger();

    $result = abortContributor($logger)->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'responseArgument'),
    );

    expect($result)->toBe([]);
    expect($logger->records)->toHaveCount(1);
});

// endregion

// region Degradation: non-literal message keeps the status

it('emits the status with the default description when the message is dynamic', function (): void {
    $logger = recordingLogger();

    $result = abortContributor($logger)->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'dynamicMessage'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(404)
        ->and($result[0]->description)->toBe('Not Found');

    expect($logger->records)->toBe([]);
});

// endregion

// region Out of scope: silent skips

it('silently skips a literal non-error status such as abort(302)', function (): void {
    $logger = recordingLogger();

    $result = abortContributor($logger)->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'redirectAbort'),
    );

    expect($result)->toBe([]);
    expect($logger->records)->toBe([]);
});

it('ignores aborts beyond the statement limit', function (): void {
    $logger = recordingLogger();

    $result = abortContributor($logger)->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'abortBeyondStatementLimit'),
    );

    expect($result)->toBe([]);
    expect($logger->records)->toBe([]);
});

it('ignores a first-class abort(...) callable', function (): void {
    $logger = recordingLogger();

    $result = abortContributor($logger)->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'firstClassCallable'),
    );

    expect($result)->toBe([]);
    expect($logger->records)->toBe([]);
});

it('stays silent when the body contains no abort at all', function (): void {
    $logger = recordingLogger();

    $result = abortContributor($logger)->contribute(
        abortActionDescriptor(AbortFixtureController::class, 'noAbortAtAll'),
    );

    expect($result)->toBe([]);
    expect($logger->records)->toBe([]);
});

it('does not match a same-namespace user-defined abort()', function (): void {
    $logger = recordingLogger();

    $result = abortContributor($logger)->contribute(
        abortActionDescriptor(AbortImpostorController::class, 'destroy'),
    );

    expect($result)->toBe([]);
    expect($logger->records)->toBe([]);
});

it('returns an empty list for a closure route without a reflected method', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );

    expect(abortContributor()->contribute($descriptor))->toBe([]);
});

// endregion
