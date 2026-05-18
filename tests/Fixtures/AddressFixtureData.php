<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Spatie\LaravelData\Data;

/**
 * Nested address fixture used by {@see ValidationRulesFixtureData} to verify
 * that nested Data class rules are resolved independently from the parent.
 */
final class AddressFixtureData extends Data
{
    public function __construct(
        public string $street,
        public string $city,
        public ?string $zip = null,
    ) {}

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return [
            'street' => ['required', 'string', 'max:200'],
            'city'   => ['required', 'string', 'max:100'],
            'zip'    => ['string', 'max:20'],
        ];
    }
}
