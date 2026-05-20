<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;

use function in_array;
use function sprintf;

/**
 * Flags a controller method that injects a `league/fractal` `Manager` but
 * carries no `#[FractalResponse]` — it produces Fractal output the generated
 * document does not describe.
 *
 * Detection is deliberately conservative: it keys off an injected `Manager`
 * parameter (matched by FQCN string via {@see PayloadParameterScanner}, so the
 * package need not be installed), not a body-inference heuristic. See
 * OAPI-053 for what this misses.
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
        $method = $operation->descriptor?->method;

        if ($operation->webhook || $method === null) {
            return;
        }

        if (!in_array(self::MANAGER_CLASS, $this->scanner->directCandidates($method), true)) {
            return;
        }

        if ($method->getAttributes(FractalResponse::class) !== []) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s %s injects a Fractal Manager but declares no #[FractalResponse]',
                $operation->method,
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
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A method injects a Fractal Manager but declares no #[FractalResponse].';
    }
}
