<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature\Plugins\ApiResources\Lint;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use LogicException;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintRunner;

uses()->group('openapi', 'plugin:api-resources');

class EmptyResponseBaseController
{
    public function show(): JsonResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

abstract class EmptyResponseAbstractResource extends JsonResource {}

class EmptyResponseAbstractController
{
    public function show(): EmptyResponseAbstractResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

class EmptyResponseConcreteResource extends JsonResource {}

class EmptyResponseConcreteController
{
    public function show(): EmptyResponseConcreteResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

/**
 * @return list<Finding>
 */
function resourceResponseEmptyFindings(string $uri): array
{
    $result = app(LintRunner::class)->run(new LintOptions(
        only: ['resource.response-empty'],
        uriGlob: $uri,
    ));

    return $result->findings;
}

it('flags a response that resolves to the base JsonResource, with a reason', function (): void {
    Route::get('re/base', [EmptyResponseBaseController::class, 'show'])->name('re.base');
    app()->forgetScopedInstances();

    $findings = resourceResponseEmptyFindings('re/base');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.response-empty')
        ->and($findings[0]->severity)->toBe(Severity::Degraded)
        ->and($findings[0]->location->routeUri)->toBe('re/base')
        ->and($findings[0]->message)->toContain('empty {data: {}} envelope')
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)
        ->toBe(EmptyResponseBaseController::class);
});

it('flags a response that resolves to an empty abstract JsonResource subclass', function (): void {
    Route::get('re/abstract', [EmptyResponseAbstractController::class, 'show'])->name('re.abstract');
    app()->forgetScopedInstances();

    $findings = resourceResponseEmptyFindings('re/abstract');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.response-empty');
});

it('does not fire for a concrete resource — that is resource.fields-undeclared territory', function (): void {
    Route::get('re/concrete', [EmptyResponseConcreteController::class, 'show'])->name('re.concrete');
    app()->forgetScopedInstances();

    expect(resourceResponseEmptyFindings('re/concrete'))->toBe([]);
});
