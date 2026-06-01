<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use LogicException;
use Radiergummi\OpenApi\Contracts\Registry\Plugin;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

uses()->group('openapi');

/**
 * Stub mirroring the SpatieDataPlugin / QueryBuilderPlugin guard pattern:
 * a plugin whose optional third-party class is not installed must short-circuit
 * `register()` without touching the registry. The pipeline tolerates the no-op.
 */
final class StubGuardedPlugin implements Plugin
{
    public function register(OpenApiRegistry $registry): void
    {
        if (!class_exists('Radiergummi\\OpenApi\\Tests\\Definitely\\NotInstalled')) {
            return;
        }

        // unreachable — guarded above
        // @codeCoverageIgnoreStart
        throw new LogicException('guard failed');
        // @codeCoverageIgnoreEnd
    }
}

it('skips a plugin whose optional dependency is not installed and generates a valid spec', function (): void {
    config()->set('openapi.plugins', [StubGuardedPlugin::class]);
    // Drop the scoped OpenApiRegistry so it rebuilds with the patched plugin list.
    app()->forgetScopedInstances();

    $spec = generateSpec();

    expect($spec['openapi'])->toBe('3.1.0')
        ->and($spec)->toHaveKey('info');
});
