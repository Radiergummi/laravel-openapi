<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Faker\Factory as FakerFactory;
use Faker\Generator as Faker;
use Throwable;

use function class_exists;
use function crc32;
use function preg_match;
use function strtolower;

/**
 * Synthesizes an example value for a field that has no authored example.
 *
 * Strict priority: this synthesizer is the **lowest** rung in the example-resolution chain.
 * Authored sources (`#[Example]` attribute, example file, inline `@example` directive) always
 * win. The synthesiser only runs when those have been tried and returned nothing.
 *
 * Wired in at: `SchemaFromFormRequest::buildSchema()` — in the per-field loop, after the
 * constant-override pass (`$constantOverrides[$fieldName]->descriptor()->applyTo($property)`)
 * has been applied; at that point all authored sources have been consulted and any remaining
 * `Generator::UNDEFINED` example slot is unclaimed.
 *
 * Targeted, not generic — the rule-name and format map is deliberately narrow to avoid the
 * lorem-ipsum leakage pattern. Unknown types/formats return `null` (no example) rather than
 * falling back to `Faker::sentence()` / `paragraph()`.
 *
 * Determinism — Faker is reseeded per-call from `(openapi.examples.faker_seed, fieldName)`.
 * Faker delegates `seed()` to PHP's global `mt_srand()`, so any code that consumes `mt_rand`
 * between construction and use (other Faker instances, tests, framework boot code) drifts the
 * RNG state. Reseeding from a deterministic per-call value makes each synthesised example
 * stable regardless of call order or surrounding RNG consumers.
 *
 * Degrades when Faker is absent — `fakerphp/faker` is declared in `require-dev`. If it is not
 * installed at runtime, every call returns `null` and no error is raised.
 *
 * @internal
 */
final readonly class FakerExampleSynthesiser
{
    private ?Faker $faker;

    private ?int $seed;

    public function __construct(
        bool $enabled = true,
        ?int $seed = 1234,
    ) {
        $this->seed = $seed;

        if (!$enabled || !class_exists(FakerFactory::class)) {
            $this->faker = null;

            return;
        }

        try {
            $this->faker = FakerFactory::create();
        } catch (Throwable) {
            $this->faker = null;
        }
    }

    public function synthesise(string $fieldName, FieldDescriptor $descriptor): mixed
    {
        if ($this->faker === null) {
            return null;
        }

        if ($this->seed !== null) {
            $this->faker->seed($this->seed ^ (int) crc32($fieldName));
        }

        if ($descriptor->enum !== null && $descriptor->enum !== []) {
            return $descriptor->enum[0];
        }

        $byFormat = $this->byFormat($descriptor->format);

        if ($byFormat !== null) {
            return $byFormat;
        }

        // Field-name hints (email_, uuid_, …) only apply when the field is actually a string.
        // Without this guard a boolean `email_verified` or integer `email_count` would be
        // assigned a fake email by virtue of its name alone.
        if ($descriptor->type === null || $descriptor->type === 'string') {
            $byName = $this->byFieldName($fieldName);

            if ($byName !== null) {
                return $byName;
            }
        }

        return $this->byType($descriptor);
    }

    private function byFormat(?string $format): mixed
    {
        if ($format === null) {
            return null;
        }

        return match (strtolower($format)) {
            'email' => $this->faker?->safeEmail(),
            'uuid' => $this->faker?->uuid(),
            'uri', 'url' => $this->faker?->url(),
            // Use dateTimeBetween with fixed bounds — iso8601()/date() use time() internally
            // and are non-deterministic despite seeding.
            'date' => $this->faker?->dateTimeBetween('2020-01-01', '2025-12-31')->format('Y-m-d'),
            'date-time' => $this->faker?->dateTimeBetween('2020-01-01', '2025-12-31')->format('Y-m-d\TH:i:s\Z'),
            'ipv4' => $this->faker?->ipv4(),
            'ipv6' => $this->faker?->ipv6(),
            'hostname' => $this->faker?->domainName(),
            default => null,
        };
    }

    private function byFieldName(string $fieldName): mixed
    {
        $lower = strtolower($fieldName);

        return match (true) {
            preg_match('/(^|_)email($|_)/', $lower) === 1 => $this->faker?->safeEmail(),
            preg_match('/(^|_)uuid($|_)/', $lower) === 1 => $this->faker?->uuid(),
            preg_match('/(^|_)url($|_)|(^|_)website($|_)/', $lower) === 1 => $this->faker?->url(),
            preg_match('/(^|_)phone($|_)/', $lower) === 1 => $this->faker?->phoneNumber(),
            default => null,
        };
    }

    private function byType(FieldDescriptor $descriptor): mixed
    {
        return match ($descriptor->type) {
            'integer' => $this->faker?->numberBetween(
                $descriptor->minimum !== null ? (int) $descriptor->minimum : 1,
                $descriptor->maximum !== null ? (int) $descriptor->maximum : 100,
            ),
            'number' => $this->faker?->randomFloat(
                2,
                $descriptor->minimum ?? 0,
                $descriptor->maximum ?? 1000,
            ),
            'boolean' => $this->faker?->boolean(),
            default => null, // no generic string fallback — avoid lorem-ipsum leakage
        };
    }
}
