<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;
use BackedEnum;
use InvalidArgumentException;

use function assert;
use function is_string;
use function sprintf;

/**
 * Adds an OpenAPI tag to an operation in addition to the namespace-derived set. Use for purely
 * additive tagging — to replace the auto-derived set entirely, use {@see Operation::$tags} with
 * `replace: true`. Class- and method-level entries merge; duplicates dedupe.
 *
 * The name may be a plain string or a string-backed {@see BackedEnum} case — the latter lets
 * consumers centralise tag taxonomies in an enum. Int-backed enums are rejected at construction
 * time because numeric tag names ("1", "2") are surprising for consumers and trip tag-naming
 * lint rules.
 *
 * ```php
 * #[OpenApi\Tag('Beta')]
 * public function experimentalEndpoint(): JsonResource { … }
 *
 * #[OpenApi\Tag(Tag::Identity)]
 * public function whoAmI(): UserResource { … }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Tag
{
    /**
     * @throws InvalidArgumentException When `$name` is an int-backed enum.
     */
    public function __construct(public string|BackedEnum $name)
    {
        if ($name instanceof BackedEnum && !is_string($name->value)) {
            throw new InvalidArgumentException(sprintf(
                '#[Tag] requires a string-backed enum; %s is backed by int. Numeric tag names are surprising for consumers.',
                $name::class,
            ));
        }
    }

    public function value(): string
    {
        if (!$this->name instanceof BackedEnum) {
            return $this->name;
        }

        // Guaranteed to be a string by the constructor's int-backed-enum guard.
        $value = $this->name->value;
        assert(is_string($value));

        return $value;
    }
}
