<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Radiergummi\OpenApi\Lint\DiffMode;
use Radiergummi\OpenApi\Lint\DiffScope;
use Radiergummi\OpenApi\Lint\LintRouteFilter;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
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
 * The returned object exposes `$capturedDiff` — the last scope passed to `changedFilesSince()`
 * (null when it was never called) — so tests can assert which scope was consulted.
 *
 * @param list<string> $diffFiles
 */
function makeStubFilter(?string $defaultRef, array $diffFiles): LintRouteFilter
{
    return new class ($defaultRef, $diffFiles) extends LintRouteFilter {
        public ?DiffScope $capturedDiff = null;

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

        protected function changedFilesSince(DiffScope $diff): array
        {
            $this->capturedDiff = $diff;

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
        uriGlob: null,
        files: [],
        diff: null,
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
        uriGlob: null,
        files: [],
        diff: new DiffScope(),
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
        uriGlob: null,
        files: [],
        diff: new DiffScope(DiffMode::Ref, 'abc123'),
    );

    expect($filter->capturedDiff?->ref)->toBe('abc123')
        ->and($filtered)->toHaveCount(1)
        ->and($filtered[0]->controller?->getName())->toBe(CleanController::class);
});

it('passes the staged scope through for --diff=staged', function (): void {
    $clean = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');
    $broken = ActionDescriptorFactory::forControllerMethod(BrokenController::class, 'stream');

    $brokenFile = (string) $broken->method?->getFileName();

    $filter = makeStubFilter(
        defaultRef: null,
        diffFiles: [relativeFromBasePath($brokenFile)],
    );

    $filtered = $filter->filter(
        descriptors: [$clean, $broken],
        uriGlob: null,
        files: [],
        diff: new DiffScope(DiffMode::StagedIndex),
    );

    expect($filter->capturedDiff?->mode)->toBe(DiffMode::StagedIndex)
        ->and($filtered)->toHaveCount(1)
        ->and($filtered[0]->controller?->getName())->toBe(BrokenController::class);
});

it('passes the working-tree scope through for --diff=working', function (): void {
    $clean = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');

    $filter = makeStubFilter(defaultRef: null, diffFiles: []);

    $filter->filter(
        descriptors: [$clean],
        uriGlob: null,
        files: [],
        diff: new DiffScope(DiffMode::WorkingTree),
    );

    expect($filter->capturedDiff?->mode)->toBe(DiffMode::WorkingTree);
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
        uriGlob: null,
        files: [],
        diff: new DiffScope(),
    );

    expect($filtered)->toHaveCount(2);
});

// endregion

// region diff command selection

it('builds the right git command for each --diff mode', function (DiffScope $diff, array $expected): void {
    $filter = new class () extends LintRouteFilter {
        /**
         * @return list<string>
         */
        public function exposeDiffCommand(DiffScope $diff): array
        {
            return $this->diffCommand($diff);
        }
    };

    expect($filter->exposeDiffCommand($diff))->toBe($expected);
})->with([
    'staged'  => [new DiffScope(DiffMode::StagedIndex), ['git', 'diff', '--cached', '--name-only']],
    'working' => [new DiffScope(DiffMode::WorkingTree), ['git', 'diff', '--name-only', 'HEAD']],
    'ref'     => [new DiffScope(DiffMode::Ref, 'main'), ['git', 'diff', '--name-only', 'main...HEAD']],
    'sha'     => [new DiffScope(DiffMode::Ref, 'abc123'), ['git', 'diff', '--name-only', 'abc123...HEAD']],
]);

// endregion

// region --path file list

it('keeps only descriptors whose controller file appears in the --path file list', function (): void {
    $clean = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');
    $broken = ActionDescriptorFactory::forControllerMethod(BrokenController::class, 'stream');

    $brokenFile = (string) $broken->method?->getFileName();

    // No --diff: the file list alone scopes the descriptor set, reusing the diff resolver.
    $filter = makeStubFilter(defaultRef: null, diffFiles: []);

    $filtered = $filter->filter(
        descriptors: [$clean, $broken],
        uriGlob: null,
        files: [relativeFromBasePath($brokenFile)],
        diff: null,
    );

    expect($filter->capturedDiff)->toBeNull()
        ->and($filtered)->toHaveCount(1)
        ->and($filtered[0]->controller?->getName())->toBe(BrokenController::class);
});

it('normalises absolute --path file values to base-relative form', function (): void {
    $clean = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');
    $broken = ActionDescriptorFactory::forControllerMethod(BrokenController::class, 'stream');

    $brokenFile = (string) $broken->method?->getFileName();

    $filter = makeStubFilter(defaultRef: null, diffFiles: []);

    $filtered = $filter->filter(
        descriptors: [$clean, $broken],
        uriGlob: null,
        files: [$brokenFile], // absolute path, as a shell would expand it
        diff: null,
    );

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]->controller?->getName())->toBe(BrokenController::class);
});

it('unions --path files with --diff changes', function (): void {
    $clean = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list');
    $broken = ActionDescriptorFactory::forControllerMethod(BrokenController::class, 'stream');

    $cleanFile = (string) $clean->method?->getFileName();
    $brokenFile = (string) $broken->method?->getFileName();

    // --diff surfaces the clean file; --path adds the broken file. Both survive.
    $filter = makeStubFilter(
        defaultRef: 'HEAD~1',
        diffFiles: [relativeFromBasePath($cleanFile)],
    );

    $filtered = $filter->filter(
        descriptors: [$clean, $broken],
        uriGlob: null,
        files: [relativeFromBasePath($brokenFile)],
        diff: new DiffScope(),
    );

    expect($filtered)->toHaveCount(2);
});

// endregion

// region missing-method routes

it('keeps a first-party route whose action method does not exist', function (): void {
    $action = CleanController::class . '@nonexistentMethod';

    // Mirrors RouteIntrospector degrading both reflectors to null for a missing method.
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], 'clean/missing', ['uses' => $action, 'controller' => $action]),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );

    $filtered = makeStubFilter(defaultRef: null, diffFiles: [])->filter(
        descriptors: [$descriptor],
        uriGlob: null,
        files: [],
        diff: null,
    );

    expect($filtered)->toHaveCount(1);
});

it('still drops a closure route', function (): void {
    $descriptor = new ActionDescriptor(
        route: new Route(['GET'], 'closure', ['uses' => static fn(): array => []]),
        controller: null,
        method: null,
        summary: null,
        description: null,
        closure: new ReflectionFunction(static fn(): array => []),
    );

    $filtered = makeStubFilter(defaultRef: null, diffFiles: [])->filter(
        descriptors: [$descriptor],
        uriGlob: null,
        files: [],
        diff: null,
    );

    expect($filtered)->toBe([]);
});

// endregion

// region --uri glob

it('keeps only descriptors whose route URI matches the --uri glob', function (): void {
    $posts = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list', uri: 'posts');
    $users = ActionDescriptorFactory::forControllerMethod(BrokenController::class, 'stream', uri: 'users');

    $filtered = makeStubFilter(defaultRef: null, diffFiles: [])->filter(
        descriptors: [$posts, $users],
        uriGlob: 'posts*',
        files: [],
        diff: null,
    );

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]->route->uri())->toBe('posts');
});

it('combines the --uri glob with a --path file list', function (): void {
    $posts = ActionDescriptorFactory::forControllerMethod(CleanController::class, 'list', uri: 'posts');
    $postsComments = ActionDescriptorFactory::forControllerMethod(BrokenController::class, 'stream', uri: 'posts/comments');

    // --uri narrows to both posts* routes; --path then keeps only the BrokenController one.
    $brokenFile = (string) $postsComments->method?->getFileName();

    $filter = makeStubFilter(defaultRef: null, diffFiles: []);

    $filtered = $filter->filter(
        descriptors: [$posts, $postsComments],
        uriGlob: 'posts*',
        files: [relativeFromBasePath($brokenFile)],
        diff: null,
    );

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]->route->uri())->toBe('posts/comments');
});

// endregion
