<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Fix\Ast\RemoveAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixApplicator;

uses()->group('openapi', 'lint', 'fix');

// The applicator used to be byte-splice-based and these cases constructed RemoveLines/ModifyAttribute
// directly. The backend is now AST-mutation only, so the cases drive RemoveAttribute against a real
// source file instead; the byte-level behaviors they pin (whole-line removal leaves no blank line,
// independent removals compose, dry-run reports but doesn't write, shared-group comma-swallowing)
// are unchanged and still asserted against byte-exact output.

function fixtureFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'openapi-fix-test-') ?: '';
    file_put_contents($path, $contents);

    return $path;
}

function removeAttributeFix(string $file, int $attributeIndex, string $member = 'index'): Fix
{
    return new Fix(
        $file,
        'description',
        'test.rule',
        new RemoveAttribute(
            new TargetSelector('FixApp\\Fixture', TargetKind::Method, $member),
            [$attributeIndex],
        ),
    );
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/openapi-fix-test-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('removes a sole-in-group attribute as a whole line, leaving no blank line behind', function (): void {
    $file = fixtureFile(<<<'PHP'
        <?php

        namespace FixApp;

        class Fixture
        {
            #[Tag('a')]
            #[Tag('b')]
            public function index(): void {}
        }

        PHP);

    $result = new FixApplicator()->apply([removeAttributeFix($file, 1)]);

    expect(file_get_contents($file))
        ->toBe(<<<'PHP'
        <?php

        namespace FixApp;

        class Fixture
        {
            #[Tag('a')]
            public function index(): void {}
        }

        PHP)
        ->and($result->applied)->toHaveCount(1)
        ->and($result->skipped)->toBe([])
        ->and($result->modifiedFiles)->toBe([$file])
        ->and($result->hasChanges)->toBeTrue();
});

it('removes several attributes from one node in a single operation without offset drift', function (): void {
    $file = fixtureFile(<<<'PHP'
        <?php

        namespace FixApp;

        class Fixture
        {
            #[Tag('a')]
            #[Tag('b')]
            #[Tag('c')]
            public function index(): void {}
        }

        PHP);

    // One operation carries both target positions (0 and 2); the second attribute survives.
    // Independent per-attribute fixes on the same node would index-shift each other, so a node's
    // removals are always expressed as a single operation.
    new FixApplicator()->apply([
        new Fix(
            $file,
            'description',
            'test.rule',
            new RemoveAttribute(
                new TargetSelector('FixApp\\Fixture', TargetKind::Method, 'index'),
                [0, 2],
            ),
        ),
    ]);

    expect(file_get_contents($file))->toBe(<<<'PHP'
        <?php

        namespace FixApp;

        class Fixture
        {
            #[Tag('b')]
            public function index(): void {}
        }

        PHP);
});

it('writes nothing in dry-run but still reports the pending change', function (): void {
    $source = <<<'PHP'
        <?php

        namespace FixApp;

        class Fixture
        {
            #[Tag('a')]
            #[Tag('b')]
            public function index(): void {}
        }

        PHP;
    $file = fixtureFile($source);

    $result = new FixApplicator()->apply([removeAttributeFix($file, 1)], dryRun: true);

    expect(file_get_contents($file))
        ->toBe($source)
        ->and($result->hasChanges)->toBeTrue()
        ->and($result->modifiedFiles)->toBe([$file]);
});

it('removes one attribute from a shared group, swallowing the comma', function (): void {
    $file = fixtureFile(<<<'PHP'
        <?php

        namespace FixApp;

        class Fixture
        {
            #[Tag('a'), Tag('b')]
            public function index(): void {}
        }

        PHP);

    new FixApplicator()->apply([removeAttributeFix($file, 1)]);

    expect(file_get_contents($file))->toBe(<<<'PHP'
        <?php

        namespace FixApp;

        class Fixture
        {
            #[Tag('a')]
            public function index(): void {}
        }

        PHP);
});

it('skips and writes nothing when the target node cannot be located', function (): void {
    $source = <<<'PHP'
        <?php

        namespace FixApp;

        class Fixture
        {
            #[Tag('a')]
            public function index(): void {}
        }

        PHP;
    $file = fixtureFile($source);

    $result = new FixApplicator()->apply([removeAttributeFix($file, 0, member: 'missing')]);

    expect(file_get_contents($file))
        ->toBe($source)
        ->and($result->applied)->toBe([])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->modifiedFiles)->toBe([]);
});
