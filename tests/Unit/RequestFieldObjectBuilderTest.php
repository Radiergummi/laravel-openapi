<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\Core\Support\RequestFieldObjectBuilder;

uses()->group('openapi');

/** A non-resource class resolved via the ref-resolver chain. */
class RequestFieldRefTarget {}

/**
 * @param list<RefSchemaResolver> $resolvers
 */
function makeRequestFieldObjectBuilder(array $resolvers = []): RequestFieldObjectBuilder
{
    return new RequestFieldObjectBuilder(static fn(): array => $resolvers);
}

function stubRefResolver(string $target, string $ref): RefSchemaResolver
{
    return new class ($target, $ref) implements RefSchemaResolver {
        public function __construct(
            private string $target,
            private string $ref,
        ) {}

        public function canResolve(string $class): bool
        {
            return $class === $this->target;
        }

        public function resolveRef(string $class): ?string
        {
            return $this->canResolve($class) ? $this->ref : null;
        }
    };
}

it('builds properties and collects required names', function (): void {
    [$properties, $required] = makeRequestFieldObjectBuilder()->propertiesAndRequired([
        new RequestField('domain', required: true, type: 'string'),
        new RequestField('php_version', type: 'string'),
    ]);

    expect($properties)
        ->toHaveCount(2)
        ->and($properties[0]->property)->toBe('domain')
        ->and($properties[1]->property)->toBe('php_version')
        ->and($required)->toBe(['domain']);
});

it('skips a field with no name', function (): void {
    [$properties, $required] = makeRequestFieldObjectBuilder()->propertiesAndRequired([
        new RequestField(null, required: true),
        new RequestField('keep'),
    ]);

    expect($properties)
        ->toHaveCount(1)
        ->and($properties[0]->property)->toBe('keep')
        ->and($required)->toBe([]);
});

it('leaves a scalar field untouched', function (): void {
    [$properties] = makeRequestFieldObjectBuilder()->propertiesAndRequired([
        new RequestField('domain', type: 'string', format: 'hostname'),
    ]);

    expect($properties[0]->property)
        ->toBe('domain')
        ->and($properties[0]->type)->toBe('string')
        ->and($properties[0]->format)->toBe('hostname');
});

it('resolves a class-string `type` to a $ref', function (): void {
    $resolver = stubRefResolver(RequestFieldRefTarget::class, '#/components/schemas/RequestFieldRefTarget');

    [$properties] = makeRequestFieldObjectBuilder([$resolver])->propertiesAndRequired([
        new RequestField('owner', type: RequestFieldRefTarget::class),
    ]);

    expect($properties[0]->property)
        ->toBe('owner')
        ->and($properties[0]->ref)->toBe('#/components/schemas/RequestFieldRefTarget');
});

it('degrades an unresolvable class-string `type` to a permissive object', function (): void {
    [$properties] = makeRequestFieldObjectBuilder()->propertiesAndRequired([
        new RequestField('owner', type: RequestFieldRefTarget::class),
    ]);

    expect($properties[0]->property)
        ->toBe('owner')
        ->and($properties[0]->type)->toBe('object');
});

it('resolves a class-string `items` on an array field to items: { $ref }', function (): void {
    $resolver = stubRefResolver(RequestFieldRefTarget::class, '#/components/schemas/RequestFieldRefTarget');

    [$properties] = makeRequestFieldObjectBuilder([$resolver])->propertiesAndRequired([
        new RequestField('owners', type: 'array', items: RequestFieldRefTarget::class),
    ]);

    expect($properties[0]->property)
        ->toBe('owners')
        ->and($properties[0]->type)->toBe('array')
        ->and($properties[0]->items)->toBeInstanceOf(OA\Items::class)
        ->and($properties[0]->items->ref)->toBe('#/components/schemas/RequestFieldRefTarget');
});

it('degrades an unresolvable class-string `items` to a permissive object item', function (): void {
    [$properties] = makeRequestFieldObjectBuilder()->propertiesAndRequired([
        new RequestField('owners', type: 'array', items: RequestFieldRefTarget::class),
    ]);

    expect($properties[0]->property)
        ->toBe('owners')
        ->and($properties[0]->type)->toBe('array')
        ->and($properties[0]->items)->toBeInstanceOf(OA\Items::class)
        ->and($properties[0]->items->type)->toBe('object');
});
