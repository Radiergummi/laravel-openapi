<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Attributes as OA;
use Spatie\LaravelData\Data;

#[OA\Schema(schema: 'DirectiveDescription')]
final class DirectiveDescriptionData extends Data
{
    public function __construct(
        // The description carries an `@example` directive (on its own line) the field attribute
        // parses out of its rendered description, so #[ResponseField] would NOT reproduce the
        // authored schema verbatim. The soundness check must reject this and the rule must log + skip.
        #[OA\Property(property: 'name', type: 'string', description: "The name.\n@example Ada")]
        public string $name,
    ) {}
}
