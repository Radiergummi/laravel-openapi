<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Plugins\Core\ErrorContributors\InlineJsonErrorContributor;
use Radiergummi\OpenApi\Plugins\Core\Support\InlineJsonCallReader;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Tests\Fixtures\InlineJsonErrorFixtureController;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses()->group('openapi');

// region Helpers

function inlineJsonErrorContributor(?LoggerInterface $logger = null): InlineJsonErrorContributor
{
    return new InlineJsonErrorContributor(
        new MethodBodyScanner(),
        new InlineJsonCallReader(),
        $logger ?? new NullLogger(),
    );
}

/**
 * @param class-string $controller
 */
function inlineJsonErrorDescriptor(
    string $method,
    string $controller = InlineJsonErrorFixtureController::class,
): ActionDescriptor {
    return new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: new ReflectionClass($controller),
        method: new ReflectionMethod($controller, $method),
        summary: null,
        description: null,
    );
}

/**
 * @return array<string, mixed>
 */
function inlineJsonErrorBodySchema(OA\Schema $schema): array
{
    /** @var array<string, mixed> $serialized */
    $serialized = json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

    return $serialized;
}

// endregion

// region Whitelisted error shapes

it('emits a 403 with the literal body schema for a straight-line non-2xx json()', function (): void {
    $result = inlineJsonErrorContributor()->contribute(
        inlineJsonErrorDescriptor('straightLineError'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(403)
        ->and($result[0]->exceptionClass)->toBe(HttpException::class)
        ->and($result[0]->bodySchema)->toBeInstanceOf(OA\Schema::class)
        ->and($result[0]->shareableDescription)->toBeFalse();

    $schema = inlineJsonErrorBodySchema($result[0]->bodySchema);

    expect($schema['type'])->toBe('object')
        ->and($schema['properties'])->toHaveKey('message')
        ->and($schema['properties']['message']['type'])->toBe('string');
});

it('emits a 403 for an error json() in a conditional guard (IncludeConditionalContexts)', function (): void {
    $result = inlineJsonErrorContributor()->contribute(
        inlineJsonErrorDescriptor('conditionalError'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(403)
        ->and($result[0]->bodySchema)->toBeInstanceOf(OA\Schema::class);
});

it('emits a 403 from the guarded-success terminal-error idiom', function (): void {
    $result = inlineJsonErrorContributor()->contribute(
        inlineJsonErrorDescriptor('guardedSuccessTerminalError'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(403);
});

it('emits a 500 for a literal server-error json()', function (): void {
    $result = inlineJsonErrorContributor()->contribute(
        inlineJsonErrorDescriptor('serverError'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(500)
        ->and($result[0]->exceptionClass)->toBe(HttpException::class);
});

it('reads a ->setStatusCode(403) override as the error status', function (): void {
    $result = inlineJsonErrorContributor()->contribute(
        inlineJsonErrorDescriptor('setStatusCodeError'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(403)
        ->and($result[0]->bodySchema)->toBeInstanceOf(OA\Schema::class);
});

it('uses NotFoundHttpException as the exception class for a 404', function (): void {
    $result = inlineJsonErrorContributor()->contribute(
        inlineJsonErrorDescriptor('notFoundError'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(404)
        ->and($result[0]->exceptionClass)->toBe(NotFoundHttpException::class);
});

it('emits one descriptor per distinct status across error branches', function (): void {
    $result = inlineJsonErrorContributor()->contribute(
        inlineJsonErrorDescriptor('twoErrorBranches'),
    );

    expect($result)->toHaveCount(2)
        ->and($result[0]->status)->toBe(403)
        ->and($result[1]->status)->toBe(404);
});

it('keeps only the first branch when two branches share a status', function (): void {
    $result = inlineJsonErrorContributor()->contribute(
        inlineJsonErrorDescriptor('twoBranchesSameStatus'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(403);

    $schema = inlineJsonErrorBodySchema($result[0]->bodySchema);

    // The first branch's body ('reason' => 'a') wins.
    expect($schema['properties'])->toHaveKey('reason');
});

it('binds the action onto the emitted descriptor', function (): void {
    $descriptor = inlineJsonErrorDescriptor('straightLineError');

    $result = inlineJsonErrorContributor()->contribute($descriptor);

    expect($result[0]->action)->toBe($descriptor);
});

// endregion

// region Degradation

it('emits a status-only 403 when the body is non-literal', function (): void {
    $logger = recordingLogger();

    $result = inlineJsonErrorContributor($logger)->contribute(
        inlineJsonErrorDescriptor('nonLiteralBody'),
    );

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(403)
        ->and($result[0]->bodySchema)->toBeNull()
        ->and($result[0]->shareableDescription)->toBeTrue()
        ->and($logger->records)->toBeEmpty();
});

it('skips a non-literal status with a generation note', function (): void {
    $logger = recordingLogger();

    $result = inlineJsonErrorContributor($logger)->contribute(
        inlineJsonErrorDescriptor('nonLiteralStatus'),
    );

    expect($result)->toBe([]);

    $noted = array_any(
        $logger->records,
        static fn(array $record): bool => $record['level'] === 'notice'
            && str_contains($record['message'], 'nonLiteralStatus')
            && str_contains($record['message'], 'status'),
    );

    expect($noted)->toBeTrue();
});

// endregion

// region Out of scope

it('silently skips a 3xx redirect literal', function (): void {
    $logger = recordingLogger();

    $result = inlineJsonErrorContributor($logger)->contribute(
        inlineJsonErrorDescriptor('redirectStatus'),
    );

    expect($result)->toBe([])
        ->and($logger->records)->toBeEmpty();
});

it('skips a 2xx literal (that is the primary scan job)', function (): void {
    $result = inlineJsonErrorContributor()->contribute(
        inlineJsonErrorDescriptor('successStatus'),
    );

    expect($result)->toBe([]);
});

it('stays silent when the body has no json call', function (): void {
    $logger = recordingLogger();

    $result = inlineJsonErrorContributor($logger)->contribute(
        inlineJsonErrorDescriptor('noJsonCall'),
    );

    expect($result)->toBe([])
        ->and($logger->records)->toBeEmpty();
});

it('returns an empty list for a closure route without a reflected method', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], '/test', static fn() => null),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );

    expect(inlineJsonErrorContributor()->contribute($descriptor))->toBe([]);
});

// endregion
