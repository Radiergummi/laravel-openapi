<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Plugins\SpatieData\SchemaFromDataClass;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Fixture {@see Data} class exercising validation-rule → OpenAPI schema constraint merging in
 * {@see SchemaFromDataClass}.
 *
 * Each property covers a distinct rule-to-constraint mapping:
 * - `name`        → maxLength from `max:250`
 * - `description` → PATCH-optional (Optional union); rule-derived `required`
 *                   must NOT make it required in the schema
 * - `score`       → minimum/maximum (integer context) from `min:0|max:100`
 * - `email`       → format=email
 * - `tags`        → array; `tags.*` rules flow into `items` schema (OAPI-016)
 * - `status`      → enum from Spatie `#[In]` attribute
 * - `address`     → nested Data; its rules resolve into the AddressFixtureData
 *                   component schema, NOT here
 * - `notes`       → `required|nullable` — must stay in required[] AND be
 *                   nullable in the schema (OAPI-003)
 */
final class ValidationRulesFixtureData extends Data
{
    public function __construct(
        public string $name,
        public string|Optional|null $description,
        public int $score,
        public string $email,
        public array $tags,
        #[In('draft', 'published')]
        public string $status,
        public AddressFixtureData $address,
        public ?string $notes,
    ) {}

    /** @return array<string, array<int, string>|string> */
    public static function rules(): array
    {
        return [
            'name'    => ['required', 'string', 'max:250'],
            'score'   => ['required', 'integer', 'min:0', 'max:100'],
            'email'   => ['required', 'email'],
            'tags'    => ['array'],
            'tags.*'  => ['string', 'max:50'],
            // OAPI-003: required + nullable — field is required, value may be null.
            'notes'   => ['required', 'nullable', 'string'],
        ];
    }
}
