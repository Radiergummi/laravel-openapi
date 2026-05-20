<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Generator;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Attributes\QueryParam;
use Radiergummi\OpenApi\Core\Generator\CoreQueryParameterResolver;
use Radiergummi\OpenApi\Tests\Support\ActionDescriptorFactory;

class QpResolverController
{
    #[QueryParam('q', type: 'string', description: 'Search query')]
    public function single(): void {}

    #[QueryParam('page', type: 'integer', default: 1, minimum: 1)]
    #[QueryParam('per_page', type: 'integer', default: 25, minimum: 1, maximum: 100)]
    public function multiple(): void {}

    #[QueryParam('cursor', type: 'string', nullable: true)]
    public function nullable(): void {}

    #[QueryParam('status', type: 'string', enum: ['draft', 'published', 'archived'])]
    public function enumerated(): void {}

    #[QueryParam('token', type: 'string', required: true)]
    public function required(): void {}

    public function bare(): void {}
}

/** @return list<string> */
function qpNames(array $parameters): array
{
    return array_map(static fn(OA\Parameter $p): string => $p->name, $parameters);
}

it('emits one parameter for a single #[QueryParam]', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QpResolverController::class, 'single');
    $params = (new CoreQueryParameterResolver())->resolveQueryParameters($descriptor);

    expect($params)->toHaveCount(1)
        ->and($params[0]->name)->toBe('q')
        ->and($params[0]->in)->toBe('query')
        ->and($params[0]->required)->toBeFalse()
        ->and($params[0]->schema->type)->toBe('string')
        ->and($params[0]->schema->description)->toBe('Search query');
});

it('emits one parameter per #[QueryParam] when multiple are present', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QpResolverController::class, 'multiple');
    $params = (new CoreQueryParameterResolver())->resolveQueryParameters($descriptor);

    expect(qpNames($params))->toBe(['page', 'per_page']);

    $page = $params[0];
    $perPage = $params[1];

    expect($page->schema->type)->toBe('integer')
        ->and($page->schema->default)->toBe(1)
        ->and($page->schema->minimum)->toBe(1)
        ->and($perPage->schema->type)->toBe('integer')
        ->and($perPage->schema->default)->toBe(25)
        ->and($perPage->schema->minimum)->toBe(1)
        ->and($perPage->schema->maximum)->toBe(100);
});

it('widens a nullable #[QueryParam] schema to the [type, null] shape', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QpResolverController::class, 'nullable');
    $params = (new CoreQueryParameterResolver())->resolveQueryParameters($descriptor);

    expect($params)->toHaveCount(1)
        ->and($params[0]->schema->type)->toBe(['string', 'null']);
});

it('forwards the enum list onto the parameter schema', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QpResolverController::class, 'enumerated');
    $params = (new CoreQueryParameterResolver())->resolveQueryParameters($descriptor);

    expect($params)->toHaveCount(1)
        ->and($params[0]->schema->enum)->toBe(['draft', 'published', 'archived']);
});

it('emits required: true when the attribute is marked required', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QpResolverController::class, 'required');
    $params = (new CoreQueryParameterResolver())->resolveQueryParameters($descriptor);

    expect($params)->toHaveCount(1)
        ->and($params[0]->required)->toBeTrue();
});

it('returns an empty array when no #[QueryParam] attributes are present', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QpResolverController::class, 'bare');

    expect((new CoreQueryParameterResolver())->resolveQueryParameters($descriptor))
        ->toBe([]);
});

#[QueryParam('tenant', type: 'string', description: 'Active tenant slug')]
#[QueryParam('locale', type: 'string', default: 'en')]
class QpClassLevelController
{
    public function inherit(): void {}

    #[QueryParam('locale', type: 'string', default: 'de')]
    #[QueryParam('page', type: 'integer', default: 1)]
    public function overrideAndAdd(): void {}
}

it('emits class-level #[QueryParam] attributes for every action', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QpClassLevelController::class, 'inherit');
    $params = (new CoreQueryParameterResolver())->resolveQueryParameters($descriptor);

    expect(qpNames($params))->toBe(['tenant', 'locale']);
});

it('lets method-level #[QueryParam] override the class-level entry with the same name', function (): void {
    $descriptor = ActionDescriptorFactory::forControllerMethod(QpClassLevelController::class, 'overrideAndAdd');
    $params = (new CoreQueryParameterResolver())->resolveQueryParameters($descriptor);

    // The order is class-level first, then method-level; the duplicate `locale` keeps its
    // original position but the method-level value replaces it.
    expect(qpNames($params))->toBe(['tenant', 'locale', 'page']);

    $locale = null;

    foreach ($params as $p) {
        if ($p->name === 'locale') {
            $locale = $p;
        }
    }

    expect($locale)->not->toBeNull()
        ->and($locale->schema->default)->toBe('de');
});
