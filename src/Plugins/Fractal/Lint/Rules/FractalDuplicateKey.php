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
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerInclude;

use function class_exists;
use function sprintf;

/**
 * Flags a transformer that declares the same output key more than once across
 * `#[TransformerField]` and/or `#[TransformerInclude]`. swagger-php emits both properties; the
 * resulting OpenAPI schema is invalid.
 */
final readonly class FractalDuplicateKey implements Rule, OperationRule
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

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($context->reflectionCache->classAttributes($transformer, TransformerField::class) as $fieldAttribute) {
            $name = $fieldAttribute->newInstance()->name;
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        foreach (
            $context->reflectionCache->classAttributes(
                $transformer,
                TransformerInclude::class,
            ) as $includeAttribute
        ) {
            $name = $includeAttribute->newInstance()->name;
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        foreach ($counts as $name => $count) {
            if ($count < 2) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '%s declares the key \'%s\' %d times across #[TransformerField]/#[TransformerInclude]',
                    $transformer,
                    $name,
                    $count,
                ),
                fixHint: 'Remove the duplicate declaration; each output key must be declared exactly once.',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'fractal.duplicate-key';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'A transformer declares the same output key in more than one #[TransformerField]/#[TransformerInclude].';
    }
}
