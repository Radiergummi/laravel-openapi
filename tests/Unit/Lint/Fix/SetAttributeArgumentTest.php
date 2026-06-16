<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Fix\Ast\SetAttributeArgument;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixApplicator;

uses()->group('openapi', 'lint', 'fix');

/**
 * Proves the AST backend supports insertion/modification, not just removal: each case drives a
 * SetAttributeArgument through the real FixApplicator against a throwaway source file and asserts
 * byte-exact output. The class is addressed structurally (FQCN + method name), so the attribute is
 * located in the cloned tree by the same flat, source-order index the backend uses everywhere.
 */
function applySetArgument(string $source, SetAttributeArgument $operation): string
{
    $file = tempnam(sys_get_temp_dir(), 'openapi-setarg-test-') ?: '';
    file_put_contents($file, $source);

    new FixApplicator()->apply([new Fix($file, 'description', 'test.rule', $operation)]);

    $after = file_get_contents($file) ?: '';
    @unlink($file);

    return $after;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/openapi-setarg-test-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

function setArgumentTarget(): TargetSelector
{
    return new TargetSelector('SetArg\\Fixture', TargetKind::Method, 'index');
}

it('appends a named argument to an attribute that has none of that name', function (): void {
    $source = <<<'PHP'
        <?php

        namespace SetArg;

        class Fixture
        {
            #[Tag(name: 'a')]
            public function index(): void {}
        }

        PHP;

    $after = applySetArgument($source, new SetAttributeArgument(
        target: setArgumentTarget(),
        attributeIndex: 0,
        argumentName: 'deprecated',
        value: true,
    ));

    expect($after)->toBe(<<<'PHP'
        <?php

        namespace SetArg;

        class Fixture
        {
            #[Tag(name: 'a', deprecated: true)]
            public function index(): void {}
        }

        PHP);
});

it('replaces the value of an existing named argument', function (): void {
    $source = <<<'PHP'
        <?php

        namespace SetArg;

        class Fixture
        {
            #[Tag(name: 'a')]
            public function index(): void {}
        }

        PHP;

    $after = applySetArgument($source, new SetAttributeArgument(
        target: setArgumentTarget(),
        attributeIndex: 0,
        argumentName: 'name',
        value: 'b',
    ));

    expect($after)->toBe(<<<'PHP'
        <?php

        namespace SetArg;

        class Fixture
        {
            #[Tag(name: 'b')]
            public function index(): void {}
        }

        PHP);
});

it('removes a named argument', function (): void {
    $source = <<<'PHP'
        <?php

        namespace SetArg;

        class Fixture
        {
            #[Tag(name: 'a', deprecated: true)]
            public function index(): void {}
        }

        PHP;

    $after = applySetArgument($source, new SetAttributeArgument(
        target: setArgumentTarget(),
        attributeIndex: 0,
        argumentName: 'deprecated',
        remove: true,
    ));

    expect($after)->toBe(<<<'PHP'
        <?php

        namespace SetArg;

        class Fixture
        {
            #[Tag(name: 'a')]
            public function index(): void {}
        }

        PHP);
});

it('skips and writes nothing when adding a named argument would collide with a positional one', function (): void {
    $source = <<<'PHP'
        <?php

        namespace SetArg;

        class Fixture
        {
            #[Tag('a')]
            public function index(): void {}
        }

        PHP;

    $file = tempnam(sys_get_temp_dir(), 'openapi-setarg-test-') ?: '';
    file_put_contents($file, $source);

    $result = new FixApplicator()->apply([
        new Fix($file, 'description', 'test.rule', new SetAttributeArgument(
            target: setArgumentTarget(),
            attributeIndex: 0,
            argumentName: 'name',
            value: 'b',
        )),
    ]);

    expect(file_get_contents($file))
        ->toBe($source)
        ->and($result->applied)->toBe([])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->modifiedFiles)->toBe([]);

    @unlink($file);
});
