<?php

declare(strict_types=1);


uses()->group('openapi');

it('clears every spec output path when no arg passed', function (): void {
    config(['openapi.specs' => ['v1' => []]]);

    $default = storage_path('openapi.yaml');
    $v1 = storage_path('openapi-v1.yaml');
    file_put_contents($default, 'x');
    file_put_contents($v1, 'x');

    $this->artisan('openapi:clear')->assertSuccessful();

    expect(file_exists($default))
        ->toBeFalse()
        ->and(file_exists($v1))->toBeFalse();
});

it('clears only the named spec', function (): void {
    config(['openapi.specs' => ['v1' => []]]);

    $default = storage_path('openapi.yaml');
    $v1 = storage_path('openapi-v1.yaml');
    file_put_contents($default, 'x');
    file_put_contents($v1, 'x');

    $this->artisan('openapi:clear v1')->assertSuccessful();

    expect(file_exists($default))
        ->toBeTrue()
        ->and(file_exists($v1))->toBeFalse();
});

it('errors gracefully on an unknown spec name', function (): void {
    $this->artisan('openapi:clear nope')->assertFailed();
});
