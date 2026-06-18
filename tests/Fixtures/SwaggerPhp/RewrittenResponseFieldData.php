<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use Radiergummi\OpenApi\Attributes\ResponseField;
use Spatie\LaravelData\Data;

/**
 * The post-rewrite twin of {@see ReplaceableAttributeData}: the `#[OA\Property]` replaced by the
 * `#[ResponseField]` the fixer emits. Used to prove the rewrite preserves the generated document.
 */
final class RewrittenResponseFieldData extends Data
{
    public function __construct(
        #[ResponseField(type: 'string', format: 'email', description: 'The contact email.')]
        public string $name,
    ) {}
}
