<?php

declare(strict_types=1);

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\OperationSubsumption;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\SchemaEquivalence;

uses()->group('openapi');

function operationSubsumption(): OperationSubsumption
{
    return new OperationSubsumption(new SchemaEquivalence());
}

it('subsumes an operation inference reproduces, with an empty candidate', function (): void {
    $inferred = new OA\Get(['path' => '/x', 'summary' => 'List things', 'tags' => ['Things']]);
    $authored = new OA\Get(['path' => '/x', 'summary' => 'List things']);

    expect(operationSubsumption()->subsumes($inferred, $authored, []))->toBeTrue();
});

it('throws on a non-empty candidate rather than silently dropping it', function (): void {
    // Operation-level candidate-replacement is unimplemented; passing a candidate must fail loudly so
    // #122 part 2 cannot wire a silently-wrong verdict.
    $inferred = new OA\Get(['path' => '/x']);
    $authored = new OA\Get(['path' => '/x']);

    operationSubsumption()->subsumes($inferred, $authored, [new OA\Parameter(['name' => 'q', 'in' => 'query'])]);
})->throws(LogicException::class);
