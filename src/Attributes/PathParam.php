<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;
use Radiergummi\OpenApi\Support\Attributes\FieldDefault;

/**
 * Documents a URI path parameter on a controller action parameter.
 *
 * The parameter type is inferred from the route binding / type-hint, so no
 * `type` is exposed. Path parameters are always present and scalar — only
 * `description`, `example`, `format`, and `pattern` apply.
 *
 * ```php
 * public function single(
 *     #[OpenApi\PathParam(description: 'The company to retrieve.', example: '01HFP…')]
 *     CompanyProfile $company,
 * ): ExternalCompanyResource { … }
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class PathParam extends FieldAttribute
{
    /**
     * @param null|non-empty-string $description
     * @param null|non-empty-string $format
     * @param null|non-empty-string $pattern
     */
    public function __construct(
        ?string $description = null,
        mixed $example = FieldDefault::Unset,
        ?string $format = null,
        ?string $pattern = null,
    ) {
        parent::__construct(
            description: $description,
            example: $example,
            format: $format,
            pattern: $pattern,
        );
    }
}
