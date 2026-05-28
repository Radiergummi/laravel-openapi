<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal;

use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;
use Radiergummi\OpenApi\Plugins\Fractal\SchemaFromTransformer;
use Radiergummi\OpenApi\Plugins\Fractal\TransformerRefSchemaResolver;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

#[TransformerField('id', type: 'integer')]
class RefFixtureTransformer {}

class NotATransformer {}

function makeTransformerRefResolver(): TransformerRefSchemaResolver
{
    $registry = new ComponentSchemaRegistry();

    return new TransformerRefSchemaResolver(new SchemaFromTransformer($registry, static fn(): array => []));
}

it('resolves a transformer-shaped class to a components ref', function (): void {
    expect(makeTransformerRefResolver()->resolveRef(RefFixtureTransformer::class))
        ->toBe('#/components/schemas/RefFixtureTransformer');
});

it('returns null for a class with no #[TransformerField]', function (): void {
    expect(makeTransformerRefResolver()->resolveRef(NotATransformer::class))->toBeNull();
});
