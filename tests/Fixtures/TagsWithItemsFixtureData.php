<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * Fixture for OAPI-016: dotted-key `foo.*` rules must populate the `items`
 * schema of the parent array property.
 */
final class TagsWithItemsFixtureData extends Data
{
    public function __construct(
        public array $tags,
    ) {}

    /** @return array<string, array<int, string>|string> */
    public static function rules(): array
    {
        return [
            'tags'   => ['array'],
            'tags.*' => ['string', 'max:10'],
        ];
    }
}
