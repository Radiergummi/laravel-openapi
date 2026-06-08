<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

/**
 * How a class's authored swagger-php schema was written — as `#[OA\*]` PHP attributes or as an
 * `@OA` PHPDoc block. The migration removal fixer needs this to pick the right edit: attributes are
 * removed via the AST, docblock blocks via line-based comment surgery.
 *
 * @internal
 */
enum AuthoredSchemaShape: string
{
    case Attribute = 'attribute';

    case Docblock = 'docblock';
}
