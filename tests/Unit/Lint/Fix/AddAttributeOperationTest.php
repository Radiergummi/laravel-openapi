<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Fix\Ast\AddAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixApplicator;

uses()->group('openapi', 'lint', 'fix');

/**
 * Drives an AddAttribute op through the real FixApplicator against a throwaway source file and
 * returns the rewritten source plus the applicator outcome, so byte-exact output and the
 * applied/skipped accounting can both be asserted. The class is addressed structurally (FQCN +
 * member), and the synthesised attribute is emitted in fully-qualified form so the use block is
 * never touched.
 */
function applyAddAttribute(string $source, AddAttribute $operation): array
{
    $file = tempnam(sys_get_temp_dir(), 'openapi-addattr-test-') ?: '';
    file_put_contents($file, $source);

    $result = new FixApplicator()->apply([new Fix($file, 'description', 'test.rule', $operation)]);

    $after = file_get_contents($file) ?: '';
    @unlink($file);

    return ['after' => $after, 'result' => $result];
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/openapi-addattr-test-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

function addAttributeTarget(): TargetSelector
{
    return new TargetSelector('AddAttr\\Fixture', TargetKind::Method, 'index');
}

it('prepends a synthesised attribute on a member that has none', function (): void {
    $source = <<<'PHP'
        <?php

        namespace AddAttr;

        class Fixture
        {
            public function index(): void {}
        }

        PHP;

    ['after' => $after, 'result' => $result] = applyAddAttribute($source, new AddAttribute(
        target: addAttributeTarget(),
        attributeClass: Radiergummi\OpenApi\Attributes\Operation::class,
        arguments: ['operationId' => 'users.index'],
    ));

    expect($after)->toBe(<<<'PHP'
        <?php

        namespace AddAttr;

        class Fixture
        {
            #[\Radiergummi\OpenApi\Attributes\Operation(operationId: 'users.index')]
            public function index(): void {}
        }

        PHP)
        ->and($result->applied)->toHaveCount(1);
});

it('prepends above an existing unrelated attribute, leaving it byte-identical', function (): void {
    $source = <<<'PHP'
        <?php

        namespace AddAttr;

        use Radiergummi\OpenApi\Attributes\Tag;

        class Fixture
        {
            #[Tag('users')]
            public function index(): void {}
        }

        PHP;

    ['after' => $after, 'result' => $result] = applyAddAttribute($source, new AddAttribute(
        target: addAttributeTarget(),
        attributeClass: Radiergummi\OpenApi\Attributes\Operation::class,
        arguments: ['operationId' => 'users.index'],
    ));

    expect($after)->toBe(<<<'PHP'
        <?php

        namespace AddAttr;

        use Radiergummi\OpenApi\Attributes\Tag;

        class Fixture
        {
            #[\Radiergummi\OpenApi\Attributes\Operation(operationId: 'users.index')]
            #[Tag('users')]
            public function index(): void {}
        }

        PHP)
        ->and($result->applied)->toHaveCount(1);
});

it('is a no-op writing nothing when the member already carries the attribute class', function (): void {
    $source = <<<'PHP'
        <?php

        namespace AddAttr;

        use Radiergummi\OpenApi\Attributes\Operation;

        class Fixture
        {
            #[Operation(summary: 'List users')]
            public function index(): void {}
        }

        PHP;

    ['after' => $after, 'result' => $result] = applyAddAttribute($source, new AddAttribute(
        target: addAttributeTarget(),
        attributeClass: Radiergummi\OpenApi\Attributes\Operation::class,
        arguments: ['operationId' => 'users.index'],
    ));

    expect($after)
        ->toBe($source)
        ->and($result->applied)->toBe([])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->modifiedFiles)->toBe([]);
});

it('renders multiple named arguments in declared order', function (): void {
    $source = <<<'PHP'
        <?php

        namespace AddAttr;

        class Fixture
        {
            public function index(): void {}
        }

        PHP;

    ['after' => $after] = applyAddAttribute($source, new AddAttribute(
        target: addAttributeTarget(),
        attributeClass: Radiergummi\OpenApi\Attributes\Operation::class,
        arguments: ['summary' => 'List users', 'operationId' => 'users.index'],
    ));

    expect($after)->toContain(
        "#[\Radiergummi\OpenApi\Attributes\Operation(summary: 'List users', operationId: 'users.index')]",
    );
});

it('is idempotent: re-running the same op against its own output changes nothing', function (): void {
    $source = <<<'PHP'
        <?php

        namespace AddAttr;

        class Fixture
        {
            public function index(): void {}
        }

        PHP;

    $operation = new AddAttribute(
        target: addAttributeTarget(),
        attributeClass: Radiergummi\OpenApi\Attributes\Operation::class,
        arguments: ['operationId' => 'users.index'],
    );

    ['after' => $once] = applyAddAttribute($source, $operation);
    ['after' => $twice, 'result' => $secondResult] = applyAddAttribute($once, $operation);

    expect($twice)
        ->toBe($once)
        ->and($secondResult->applied)->toBe([]);
});
