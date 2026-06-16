<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;

use function in_array;
use function sprintf;

/**
 * Flags a controller method that injects a Fractal `Manager` but carries no `#[FractalResponse]`.
 *
 * Detection uses an injected `Manager` parameter (matched by FQCN, no package install required).
 * Ships at level 2 because the `fractal()` helper and facade never inject a `Manager`, so they
 * would never trigger this rule; silencing them at level 1 would be misleading.
 */
final readonly class FractalResponseUnbound implements Rule, OperationRule
{
    private const string MANAGER_CLASS = 'League\\Fractal\\Manager';

    public function __construct(
        private PayloadParameterScanner $scanner,
    ) {}

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $descriptor = $operation->descriptor;
        $method = $descriptor?->method;

        if ($operation->webhook || $descriptor === null || $method === null) {
            return;
        }

        if (!in_array(self::MANAGER_CLASS, $this->scanner->directCandidates($method), true)) {
            return;
        }

        if ($descriptor->actionAttributes(FractalResponse::class) !== []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s injects a Fractal Manager but declares no #[FractalResponse]',
                $operation->method->forDisplay(),
                $operation->pathUri,
            ),
            fixHint: 'Add #[FractalResponse(transformer: SomeTransformer::class)] to the action.',
        );
    }

    #[Override]
    public function id(): string
    {
        return 'fractal.response-unbound';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A method injects a Fractal Manager but declares no #[FractalResponse]. '
            . 'Does not cover the fractal() helper or the Spatie\\Fractalistic\\Fractal facade, '
            . 'which are invoked inside method bodies and never inject a Manager.';
    }
}
