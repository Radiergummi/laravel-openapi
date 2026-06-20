<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Ast\RemoveAttribute;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetKind;
use Radiergummi\OpenApi\Lint\Fix\Ast\TargetSelector;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixableRule;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\Fix\FixRunner;
use Radiergummi\OpenApi\Lint\Fix\FixSafety;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\RuleRegistry;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\FixCommandLinkController;

uses()->group('openapi', 'lint', 'fix');

/**
 * The Destructive tier has no production producers yet (#199 ships the first), so the runner's
 * partition is exercised by binding a synthetic rule that emits one FixSafety::Destructive fix.
 * The fix targets an absent member, so the applicator never mutates a real file: this test is about
 * partition accounting, not file IO.
 */
function bindSyntheticDestructiveRule(): void
{
    $rule = new class () implements FixableRule, OperationRule {
        public function checkOperation(OperationNode $operation, LintContext $context): iterable
        {
            yield new Finding(ruleId: $this->id, severity: Severity::Degraded, message: 'synthetic');
        }

        public string $id = 'synthetic.destructive';

        public Severity $severity = Severity::Degraded;

        public string $description = 'synthetic destructive rule';

        public function fixer(): Fixer
        {
            // Target a real, readable file but an absent member, so even when this destructive fix
            // reaches the applicator it is a node-not-found no-op (never mutates the fixture).
            $file = new ReflectionClass(FixCommandLinkController::class)->getFileName() ?: '';

            return new class ($file) implements Fixer {
                public function __construct(private string $file) {}

                public function fix(Finding $finding, FixContext $context): iterable
                {
                    yield new Fix(
                        $this->file,
                        'synthetic destructive',
                        $finding->ruleId,
                        new RemoveAttribute(
                            new TargetSelector(FixCommandLinkController::class, TargetKind::Method, 'absent'),
                            [0],
                        ),
                        FixSafety::Destructive,
                    );
                }
            };
        }
    };

    app()->instance(RuleRegistry::class, new RuleRegistry([$rule]));
}

beforeEach(function (): void {
    Route::get('partition/route', [FixCommandLinkController::class, 'index']);
    bindSyntheticDestructiveRule();
});

it('withholds a destructive fix under --fix=safe and keeps its finding in remaining', function (): void {
    $outcome = app(FixRunner::class)->run(new LintOptions(level: 2), dryRun: false, applyDestructive: false);

    expect($outcome->withheldDestructiveCount)->toBeGreaterThan(0)
        ->and($outcome->fixResult->applied)->toBe([])
        // Tightening 3: a withheld-only finding is counted AND remains an unresolved gap.
        ->and($outcome->remainingFindings)->not->toBeEmpty()
        ->and($outcome->remainingFindings[0]->ruleId)->toBe('synthetic.destructive');
});

it('routes destructive fixes to the applicator under --fix=dangerous (none withheld)', function (): void {
    // dryRun avoids the working-tree guard and any real write; we assert only the partition routing.
    $outcome = app(FixRunner::class)->run(new LintOptions(level: 2), dryRun: true, applyDestructive: true);

    expect($outcome->withheldDestructiveCount)->toBe(0);
});
