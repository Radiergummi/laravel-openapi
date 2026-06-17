<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

/**
 * How risky applying a {@see Fix} is.
 *
 * `Safe` fixes mutate the source at, or tightly bound to, the finding site and are easy to eyeball
 * (the default for every fixer). `Destructive` fixes touch files a developer hand-curates or write
 * far from the finding (e.g. rewriting `config/openapi.php`); they are withheld from a plain
 * `--fix` and only applied under `--fix=dangerous` against a clean working tree.
 *
 * @internal
 */
enum FixSafety
{
    case Safe;

    case Destructive;
}
