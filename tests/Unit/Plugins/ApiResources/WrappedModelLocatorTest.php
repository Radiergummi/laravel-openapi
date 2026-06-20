<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\ApiResources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\WrappedModelLocator;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;

uses()->group('openapi', 'plugin:api-resources');

/**
 * The app-side generic resource base larastan-style codebases declare.
 *
 * @template TModel of Model
 */
abstract class GenericBaseResource extends JsonResource {}

/** @mixin Article */
class MixinTaggedResource extends JsonResource {}

/** @extends GenericBaseResource<Article> */
class ExtendsTaggedResource extends GenericBaseResource {}

/**
 * @mixin Author
 *
 * @extends GenericBaseResource<Article>
 */
class BothTagsResource extends GenericBaseResource {}

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

it('resolves a non-Model wrapped class as a value object', function (): void {
    expect(wrappedModelLocator()->locateValueObject(NonModelMixinResource::class))->toBe(DocBlockParser::class);
});

it('returns null from locateValueObject when the wrapped class is a Model', function (): void {
    expect(wrappedModelLocator()->locateValueObject(MixinTaggedResource::class))->toBeNull();
});

it('returns null from locateValueObject when no wrapped class is declared', function (): void {
    expect(wrappedModelLocator()->locateValueObject(UntaggedResource::class))->toBeNull();
});
