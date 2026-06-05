<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

use function array_values;

/**
 * Declares one branch of a discriminated request body. Stack it (repeatable) on a controller
 * action alongside `#[RequestBody(discriminator: '…')]`. Supply exactly one of:
 *
 * - inline `#[RequestField]`s describing the branch's shape, or
 * - `schema:` — a class-string the ref-resolver chain can build (a Spatie Data class, API
 *   Resource, …; not a FormRequest).
 *
 * ```php
 * #[RequestBody(discriminator: 'provider')]
 * #[RequestVariant('aws', fields: new RequestField('region', required: true))]
 * #[RequestVariant('custom', schema: CustomProviderData::class)]
 * public function store(Request $request) { … }
 * ```
 *
 * @api
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class RequestVariant
{
    /** @var list<RequestField> */
    public array $fields;

    /**
     * @param non-empty-string  $value  The discriminator string clients send for this branch.
     * @param null|class-string $schema A ref-resolvable class for this branch's schema.
     */
    public function __construct(
        public string $value,
        public ?string $schema = null,
        RequestField ...$fields,
    ) {
        $this->fields = array_values($fields);
    }

    /**
     * True when the branch supplies neither or both of `schema` / `fields` — exactly one is
     * required.
     */
    public function isMalformed(): bool
    {
        return ($this->schema !== null) === ($this->fields !== []);
    }
}
