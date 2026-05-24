<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\FractalResponse;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;

use function class_exists;
use function sprintf;

/**
 * Flags a `#[TransformerInclude]` declared with no `transformer` class — the included resource
 * is emitted as an opaque `type: object`.
 */
final readonly class FractalIncludeTransformerMissing implements Rule, OperationRule
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $descriptor = $operation->descriptor;

        if ($operation->webhook || $descriptor === null) {
            return;
        }

        $attribute = $descriptor->actionAttributes(FractalResponse::class)[0] ?? null;

        if ($attribute === null) {
            return;
        }

        $transformer = $attribute->newInstance()->transformer;

        if (!class_exists($transformer)) {
            return;
        }

        foreach (
            $context->reflectionCache->classAttributes(
                $transformer,
                TransformerInclude::class,
            ) as $includeAttribute
        ) {
            $include = $includeAttribute->newInstance();

            if ($include->transformer !== null) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '#[TransformerInclude(\'%s\')] on %s has no transformer — the include is emitted as an opaque object',
                    $include->name,
                    $transformer,
                ),
                fixHint: 'Add transformer: to #[TransformerInclude] naming the included resource\'s transformer.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'fractal.include-transformer-missing';
    }

    #[Override]
    public function level(): int
    {
        return 2;
    }

    #[Override]
    public function description(): string
    {
        return 'A #[TransformerInclude] is declared without a transformer class.';
    }
}
