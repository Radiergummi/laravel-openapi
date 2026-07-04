<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\MethodBody;

use PhpParser\Node\Arg;

use function array_find;

/**
 * Resolves a call argument the way PHP binds it: by name if the caller named it, otherwise by
 * positional index. A named argument matches at any position; an unnamed argument matches only at
 * the given slot.
 *
 * Pure: it does not filter spread arguments or fold literals. Callers keep their own such logic.
 *
 * @internal
 */
final readonly class CallArgumentResolver
{
    /**
     * @param array<int, Arg> $arguments
     */
    public static function argument(array $arguments, string $name, int $position): ?Arg
    {
        return array_find(
            $arguments,
            static fn(Arg $argument, int $index): bool
                => $argument->name === null ? $index === $position : $argument->name->toString() === $name,
        );
    }
}
