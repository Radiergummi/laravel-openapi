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

class BareLintResource extends JsonResource {}

class BareLintController
{
    public function show(): BareLintResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

class AbstractReturnLintController
{
    public function returnsAbstract(): JsonResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

class PopulatedLintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

class PopulatedLintController
{
    public function show(): PopulatedLintResource
    {
        throw new LogicException('Signature-only fixture; never invoked.');
    }
}

/**
 * @return list<Finding>
 */
function resourceFieldsUndeclaredFindings(string $uri): array
{
    $result = app(LintRunner::class)->run(new LintOptions(
        only: ['resource.fields-undeclared'],
        uriGlob: $uri,
    ));

    return $result->findings;
}

it('flags a concrete resource that declares no #[ResourceField], with a reason', function (): void {
    Route::get('fu/bare', [BareLintController::class, 'show'])->name('fu.bare');
    app()->forgetScopedInstances();

    $findings = resourceFieldsUndeclaredFindings('fu/bare');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->ruleId)->toBe('resource.fields-undeclared')
        ->and($findings[0]->severity)->toBe(Severity::Degraded)
        ->and($findings[0]->location->routeUri)->toBe('fu/bare')
        ->and($findings[0]->message)->toContain('declares no #[ResourceField]')
        ->and($findings[0]->context[Finding::CONTEXT_SOURCE_CLASS] ?? null)
        ->toBe(BareLintController::class);
});

it('does not fire when the action returns the abstract JsonResource base', function (): void {
    Route::get('fu/abstract', [AbstractReturnLintController::class, 'returnsAbstract'])->name('fu.abstract');
    app()->forgetScopedInstances();

    expect(resourceFieldsUndeclaredFindings('fu/abstract'))->toBe([]);
});

it('does not fire when the reader resolves a non-empty declared shape from toArray()', function (): void {
    Route::get('fu/populated', [PopulatedLintController::class, 'show'])->name('fu.populated');
    app()->forgetScopedInstances();

    expect(resourceFieldsUndeclaredFindings('fu/populated'))->toBe([]);
});
