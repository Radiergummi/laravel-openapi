<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\WrappedModelLocator;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;

uses()->group('openapi', 'plugin:api-resources');

/** @mixin Article */
class MixinTaggedResource extends JsonResource {}

/** @extends JsonResource<Article> */
class ExtendsTaggedResource extends JsonResource {}

/**
 * @mixin Author
 *
 * @extends JsonResource<Article>
 */
class BothTagsResource extends JsonResource {}

/** @mixin DocBlockParser */
class NonModelMixinResource extends JsonResource {}

class UntaggedResource extends JsonResource {}

function wrappedModelLocator(): WrappedModelLocator
{
    return new WrappedModelLocator(
        docBlockParser: DocBlockParser::create(),
        typeNodeResolver: TypeNodeResolver::create(),
    );
}

it('resolves the wrapped model from an @mixin tag', function (): void {
    expect(wrappedModelLocator()->locate(MixinTaggedResource::class))->toBe(Article::class);
});

it('resolves the wrapped model from a generic @extends tag', function (): void {
    expect(wrappedModelLocator()->locate(ExtendsTaggedResource::class))->toBe(Article::class);
});

it('prefers @mixin over @extends', function (): void {
    expect(wrappedModelLocator()->locate(BothTagsResource::class))->toBe(Author::class);
});

it('rejects an @mixin that is not an Eloquent model', function (): void {
    expect(wrappedModelLocator()->locate(NonModelMixinResource::class))->toBeNull();
});

it('returns null for a resource without a docblock', function (): void {
    expect(wrappedModelLocator()->locate(UntaggedResource::class))->toBeNull();
});
