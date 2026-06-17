<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Fix\Ast\RemoveAttribute;
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
