<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Support\Str;
use Radiergummi\OpenApi\Core\Lint\LintRouteFilter;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\BrokenController;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\CleanController;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

uses()->group('openapi', 'lint');

// region Helpers

/**
 * Returns a `LintRouteFilter` whose protected git-shelling seams are stubbed.
 *
 * `$defaultRef` is the value `resolveDefaultDiffRef()` returns when the caller passes a bare
 * --diff. Pass `null` to make the test fail loudly if the default resolution path runs (used to
 * assert an explicit ref bypasses it).
 *
 * The returned object exposes `$capturedRef` — the last ref passed to `changedFilesSince()` — so
 * tests can assert which ref was consulted.
 *
 * @param list<string> $diffFiles
 */
function makeStubFilter(?string $defaultRef, array $diffFiles): LintRouteFilter
{
    return new class ($defaultRef, $diffFiles) extends LintRouteFilter {
        public ?string $capturedRef = null;

        /**
         * @param list<string> $changedFiles
         */
        public function __construct(
            private readonly ?string $stubDefaultRef,
            private readonly array $changedFiles,
        ) {}

        protected function resolveDefaultDiffRef(): string
        {
            if ($this->stubDefaultRef === null) {
                throw new RuntimeException('resolveDefaultDiffRef must not be called');
            }

            return $this->stubDefaultRef;
        }

        protected function changedFilesSince(string $ref): array
        {
            $this->capturedRef = $ref;

            return $this->changedFiles;
        }
    };
}

function relativeFromBasePath(string $absolute): string
{
    return Str::after($absolute, base_path() . '/');
}

// endregion

// region --diff flag

it('does not filter when --diff is disabled', function (): void {
    $clean = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');
    $broken = ActionDescriptorFactory::forControllerMethod(BrokenController::class, 'stream');

    $filtered = makeStubFilter(defaultRef: 'HEAD', diffFiles: [])->filter(
        descriptors: [$clean, $broken],
        path: null,
        diffEnabled: false,
        diffRef: null,
    );

    expect($filtered)->toHaveCount(2);
});

it('keeps only descriptors whose controller file appears in the diff', function (): void {
    $clean = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');
    $broken = ActionDescriptorFactory::forControllerMethod(BrokenController::class, 'stream');

    $brokenFile = (string) $broken->method?->getFileName();

    $filter = makeStubFilter(
        defaultRef: 'HEAD~1',
        diffFiles: [relativeFromBasePath($brokenFile)],
    );

    $filtered = $filter->filter(
        descriptors: [$clean, $broken],
        path: null,
        diffEnabled: true,
        diffRef: null,
    );

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]->controller?->getName())->toBe(BrokenController::class);
});

it('uses an explicit --diff=ref instead of resolving the default ref', function (): void {
    $clean = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');
    $broken = ActionDescriptorFactory::forControllerMethod(BrokenController::class, 'stream');

    $cleanFile = (string) $clean->method?->getFileName();

    $filter = makeStubFilter(
        defaultRef: null,
        diffFiles: [relativeFromBasePath($cleanFile)],
    );

    $filtered = $filter->filter(
        descriptors: [$clean, $broken],
        path: null,
        diffEnabled: true,
        diffRef: 'abc123',
    );

    expect($filter->capturedRef)->toBe('abc123')
        ->and($filtered)->toHaveCount(1)
        ->and($filtered[0]->controller?->getName())->toBe(CleanController::class);
});

it('preserves every descriptor when the openapi config file is in the diff', function (): void {
    $clean = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');
    $broken = ActionDescriptorFactory::forControllerMethod(BrokenController::class, 'stream');

    $configRelative = relativeFromBasePath(config_path('openapi.php'));

    $filter = makeStubFilter(
        defaultRef: 'HEAD~1',
        diffFiles: [$configRelative],
    );

    $filtered = $filter->filter(
        descriptors: [$clean, $broken],
        path: null,
        diffEnabled: true,
        diffRef: null,
    );

    expect($filtered)->toHaveCount(2);
});

// endregion
