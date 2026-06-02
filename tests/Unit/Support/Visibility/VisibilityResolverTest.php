<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Attributes\Expose;
use Radiergummi\OpenApi\Attributes\Hide;
use Radiergummi\OpenApi\Support\Visibility\VisibilityMode;
use Radiergummi\OpenApi\Support\Visibility\VisibilityResolver;

$resolver = fn(VisibilityMode $mode = VisibilityMode::Public): VisibilityResolver => new VisibilityResolver($mode);

it('returns visible in public default with no attributes', function () use ($resolver): void {
    expect($resolver()->isVisible([], [], 'production'))->toBeTrue();
});

it('returns hidden in hidden default with no attributes', function () use ($resolver): void {
    expect($resolver(VisibilityMode::Hidden)->isVisible([], [], 'production'))->toBeFalse();
});

it('hides when an unconditional Hide applies', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide()], [], 'production'))->toBeFalse();
});

it('hides when Hide(only:) matches the env', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide(only: ['production'])], [], 'production'))->toBeFalse();
});

it('does not hide when Hide(only:) misses the env', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide(only: ['production'])], [], 'local'))->toBeTrue();
});

it('hides when Hide(except:) does not list the env', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide(except: ['local'])], [], 'production'))->toBeFalse();
});

it('does not hide when Hide(except:) lists the env', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide(except: ['local'])], [], 'local'))->toBeTrue();
});

it('exposes when Expose applies in hidden default', function () use ($resolver): void {
    expect($resolver(VisibilityMode::Hidden)->isVisible([], [new Expose()], 'production'))->toBeTrue();
});

it('does not expose when Expose(only:) misses the env in hidden default', function () use ($resolver): void {
    expect($resolver(VisibilityMode::Hidden)->isVisible([], [new Expose(only: ['staging'])], 'production'))->toBeFalse();
});

it('lets Hide beat Expose when both apply', function () use ($resolver): void {
    expect($resolver()->isVisible([new Hide()], [new Expose()], 'production'))->toBeFalse()
        ->and($resolver(VisibilityMode::Hidden)->isVisible([new Hide()], [new Expose()], 'production'))->toBeFalse();
});

it('falls through to Expose when a present Hide misses the env', function () use ($resolver): void {
    // Hide is present but scoped away from this env, so the next rule — Expose — decides; in the
    // hidden default this is the only path to a visible route.
    expect($resolver(VisibilityMode::Hidden)->isVisible([new Hide(only: ['local'])], [new Expose()], 'production'))
        ->toBeTrue();
});

it('falls through to the default when both a present Hide and Expose miss the env', function () use ($resolver): void {
    // Neither attribute applies in this env, so the configured default mode decides.
    expect($resolver(VisibilityMode::Hidden)->isVisible([new Hide(only: ['local'])], [new Expose(only: ['staging'])], 'production'))
        ->toBeFalse()
        ->and($resolver()->isVisible([new Hide(only: ['local'])], [new Expose(only: ['staging'])], 'production'))
        ->toBeTrue();
});
