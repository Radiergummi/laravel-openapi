<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Types;

use RuntimeException;

/**
 * The declaring class carries a malformed `@phpstan-import-type` alias, which makes
 * symfony/type-info's TypeContextFactory throw while building the type context. Used to
 * verify {@see \Radiergummi\OpenApi\Support\Types\TypeNodeResolver} degrades to a
 * context-free resolution instead of aborting the generation/lint run.
 *
 * Excluded from the main PHPStan pass (see phpstan.neon excludePaths) — the broken
 * annotation is the whole point of the fixture.
 *
 * @phpstan-import-type Missing from \This\Class\Does\Not\Exist
 */
final class BrokenTypeContext
{
    /** @throws RuntimeException */
    public function boom(): void {}
}
