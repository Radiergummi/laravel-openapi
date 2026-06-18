<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Fix\Ast\RemoveAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\RewriteToAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\SetAttributeArgument;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixApplicator;
use Radiergummi\OpenApi\Lint\Fix\FixSkipReason;

uses()->group('openapi', 'lint', 'fix');

function conflictFixtureFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'openapi-conflict-test-') ?: '';
    file_put_contents($path, $contents);

    return $path;
}

function setOperationIdFix(string $file, string $value): Fix
{
    return new Fix(
        $file,
        'description',
        'operation.id-invalid-chars',
        new SetAttributeArgument(
            new TargetSelector('Conflict\\Fixture', TargetKind::Method, 'index'),
            attributeIndex: 0,
            argumentName: 'operationId',
            value: $value,
        ),
    );
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/openapi-conflict-test-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('applies the safe subset, skips the conflicting fix with a reason, and stays byte-stable', function (): void {
    $source = <<<'PHP'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[Operation(operationId: 'bad id')]
            public function index(): void {}
        }

        PHP;
    $file = conflictFixtureFile($source);

    $first = setOperationIdFix($file, 'first');
    $second = setOperationIdFix($file, 'second');

    $result = new FixApplicator()->apply([$first, $second]);

    expect(file_get_contents($file))->toBe(<<<'PHP'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[Operation(operationId: 'first')]
            public function index(): void {}
        }

        PHP)
        ->and($result->applied)->toBe([$first])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->fix)->toBe($second)
        ->and($result->skipped[0]->reason)->toBe(FixSkipReason::Conflict)
        ->and($result->modifiedFiles)->toBe([$file]);
});

it('applies the previously-skipped fix on a subsequent run (progress on re-run)', function (): void {
    $source = <<<'PHP'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[Operation(operationId: 'bad id')]
            public function index(): void {}
        }

        PHP;
    $file = conflictFixtureFile($source);

    new FixApplicator()->apply([setOperationIdFix($file, 'first'), setOperationIdFix($file, 'second')]);

    // The next lint pass re-emits only the still-needed fix; it now has no surviving conflictor.
    $reRun = new FixApplicator()->apply([setOperationIdFix($file, 'second')]);

    expect($reRun->applied)->toHaveCount(1)
        ->and($reRun->skipped)->toBe([])
        ->and(file_get_contents($file))->toContain("#[Operation(operationId: 'second')]");
});

it('reports a mechanical node-not-found skip distinctly from a conflict skip', function (): void {
    $source = <<<'PHP'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[Operation(operationId: 'bad id')]
            public function index(): void {}
        }

        PHP;
    $file = conflictFixtureFile($source);

    $missing = new Fix(
        $file,
        'description',
        'operation.id-invalid-chars',
        new RemoveAttribute(
            new TargetSelector('Conflict\\Fixture', TargetKind::Method, 'absentMethod'),
            [0],
        ),
    );

    $result = new FixApplicator()->apply([$missing]);

    expect($result->applied)->toBe([])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->reason)->toBe(FixSkipReason::NodeNotFound);
});

function removeIndexFix(string $file, int $index): Fix
{
    return new Fix(
        $file,
        'description',
        'tag.duplicate',
        new RemoveAttribute(
            new TargetSelector('Conflict\\Fixture', TargetKind::Method, 'index'),
            [$index],
        ),
    );
}

it('skips a Set that follows a Remove on the same member, leaving the operationId untouched, then applies it on re-run', function (): void {
    // The shipped-fixer-reachable case: a duplicate #[Tag] removal (flat 0) plus an operationId set
    // (flat 1). Applying both in one traversal would compact the list and the set would address the
    // wrong attribute. The set must be skipped, not silently mutate the wrong node.
    $source = <<<'PHP'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[Tag('dup')]
            #[Operation(operationId: 'bad id')]
            public function index(): void {}
        }

        PHP;
    $file = conflictFixtureFile($source);

    $remove = removeIndexFix($file, 0);
    $set = new Fix(
        $file,
        'description',
        'operation.id-invalid-chars',
        new SetAttributeArgument(
            new TargetSelector('Conflict\\Fixture', TargetKind::Method, 'index'),
            attributeIndex: 1,
            argumentName: 'operationId',
            value: 'fixed',
        ),
    );

    $result = new FixApplicator()->apply([$remove, $set]);

    expect(file_get_contents($file))->toBe(<<<'PHP'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[Operation(operationId: 'bad id')]
            public function index(): void {}
        }

        PHP)
        ->and($result->applied)->toBe([$remove])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->fix)->toBe($set)
        ->and($result->skipped[0]->reason)->toBe(FixSkipReason::Conflict);

    // The re-emitted set now resolves against the compacted layout (Operation at flat 0) and lands.
    $reRun = new FixApplicator()->apply([
        new Fix(
            $file,
            'description',
            'operation.id-invalid-chars',
            new SetAttributeArgument(
                new TargetSelector('Conflict\\Fixture', TargetKind::Method, 'index'),
                attributeIndex: 0,
                argumentName: 'operationId',
                value: 'fixed',
            ),
        ),
    ]);

    expect($reRun->applied)->toHaveCount(1)
        ->and(file_get_contents($file))->toContain("#[Operation(operationId: 'fixed')]");
});

it('applies only the first of two disjoint Removes on one member, skips the second, then finishes on re-run', function (): void {
    // Intent: drop A (flat 0) and C (flat 2), keep B and D. Run together, the first removal compacts
    // the list so the second op's index 2 no longer points at C. The second must be skipped.
    $source = <<<'PHP'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[Tag('a')]
            #[Tag('b')]
            #[Tag('c')]
            #[Tag('d')]
            public function index(): void {}
        }

        PHP;
    $file = conflictFixtureFile($source);

    $removeA = removeIndexFix($file, 0);
    $removeC = removeIndexFix($file, 2);

    $result = new FixApplicator()->apply([$removeA, $removeC]);

    expect(file_get_contents($file))->toBe(<<<'PHP'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[Tag('b')]
            #[Tag('c')]
            #[Tag('d')]
            public function index(): void {}
        }

        PHP)
        ->and($result->applied)->toBe([$removeA])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->fix)->toBe($removeC)
        ->and($result->skipped[0]->reason)->toBe(FixSkipReason::Conflict);

    // On the next pass C is now at flat index 1 (b, c, d); re-emitting its removal lands cleanly.
    $reRun = new FixApplicator()->apply([removeIndexFix($file, 1)]);

    expect($reRun->applied)->toHaveCount(1)
        ->and(file_get_contents($file))->toBe(<<<'PHP'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[Tag('b')]
            #[Tag('d')]
            public function index(): void {}
        }

        PHP);
});

function rewriteToQueryParamFix(string $file, int $oaParameterIndex, string $name): Fix
{
    return new Fix(
        $file,
        'description',
        'migration.oa-replaceable-by-attribute',
        new RewriteToAttribute(
            new TargetSelector('Conflict\\Fixture', TargetKind::Method, 'index'),
            'Radiergummi\\OpenApi\\Attributes\\QueryParam',
            ['name' => $name],
            attributeIndices: [$oaParameterIndex],
        ),
    );
}

it('applies one RewriteToAttribute on a shared method node, skips the second, then converges on re-run', function (): void {
    // Reachable: the rule emits one finding per query #[OA\Parameter], so a method with two
    // replaceable parameters yields two Rewrites on one Method node. Both change attribute layout,
    // so the conflict detector keeps the first; the #[OA\Get] must survive and the file stay valid.
    $source = <<<'PHP_SRC'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[OA\Get(path: '/things')]
            #[OA\Parameter(name: 'a', in: 'query')]
            #[OA\Parameter(name: 'b', in: 'query')]
            public function index(): void {}
        }

        PHP_SRC;
    $file = conflictFixtureFile($source);

    $first = rewriteToQueryParamFix($file, 1, 'a');
    $second = rewriteToQueryParamFix($file, 2, 'b');

    $result = new FixApplicator()->apply([$first, $second]);

    expect($result->applied)->toBe([$first])
        ->and($result->skipped)->toHaveCount(1)
        ->and($result->skipped[0]->fix)->toBe($second)
        ->and($result->skipped[0]->reason)->toBe(FixSkipReason::Conflict)
        ->and(file_get_contents($file))->toBe(<<<'PHP_SRC'
        <?php

        namespace Conflict;

        class Fixture
        {
            #[\Radiergummi\OpenApi\Attributes\QueryParam(name: 'a')]
            #[OA\Get(path: '/things')]
            #[OA\Parameter(name: 'b', in: 'query')]
            public function index(): void {}
        }

        PHP_SRC);

    // On re-run, parameter 'b' is now at flat index 2 again (QueryParam=0, OA\Get=1, OA\Parameter b=2);
    // re-emitting its rewrite lands with no surviving conflictor.
    $reRun = new FixApplicator()->apply([rewriteToQueryParamFix($file, 2, 'b')]);

    expect($reRun->applied)->toHaveCount(1)
        ->and($reRun->skipped)->toBe([])
        ->and(file_get_contents($file))
        ->toContain("#[\Radiergummi\OpenApi\Attributes\QueryParam(name: 'a')]")
        ->toContain("#[\Radiergummi\OpenApi\Attributes\QueryParam(name: 'b')]")
        ->not->toContain('#[OA\Parameter')
        ->toContain("#[OA\Get(path: '/things')]");
});

it('applies two RewriteToAttribute fixes on sibling promoted params in one pass, byte-preserving', function (): void {
    // Neighboring-node: two replaceable #[OA\Property] on distinct promoted params of one Data class.
    // Different TargetSelectors never conflict, so both land in a single applicator pass.
    $source = <<<'PHP_SRC'
        <?php

        namespace Conflict;

        class Fixture
        {
            public function __construct(
                #[OA\Property(property: 'name', type: 'string')]
                public string $name,
                #[OA\Property(property: 'count', type: 'integer')]
                public int $count,
            ) {}
        }

        PHP_SRC;
    $file = conflictFixtureFile($source);

    $nameFix = new Fix(
        $file,
        'description',
        'migration.oa-replaceable-by-attribute',
        new RewriteToAttribute(
            new TargetSelector('Conflict\\Fixture', TargetKind::Property, 'name'),
            'Radiergummi\\OpenApi\\Attributes\\ResponseField',
            ['type' => 'string'],
            attributeIndices: [0],
        ),
    );
    $countFix = new Fix(
        $file,
        'description',
        'migration.oa-replaceable-by-attribute',
        new RewriteToAttribute(
            new TargetSelector('Conflict\\Fixture', TargetKind::Property, 'count'),
            'Radiergummi\\OpenApi\\Attributes\\ResponseField',
            ['type' => 'integer'],
            attributeIndices: [0],
        ),
    );

    $result = new FixApplicator()->apply([$nameFix, $countFix]);

    expect($result->applied)->toHaveCount(2)
        ->and($result->skipped)->toBe([])
        ->and(file_get_contents($file))->toBe(<<<'PHP_SRC'
        <?php

        namespace Conflict;

        class Fixture
        {
            public function __construct(
                #[\Radiergummi\OpenApi\Attributes\ResponseField(type: 'string')]
                public string $name,
                #[\Radiergummi\OpenApi\Attributes\ResponseField(type: 'integer')]
                public int $count,
            ) {}
        }

        PHP_SRC);
});
