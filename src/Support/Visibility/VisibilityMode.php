<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Visibility;

/**
 * Visibility Mode
 *
 * @internal
 */
enum VisibilityMode: string
{
    case Public = 'public';
    case Hidden = 'hidden';

    public static function fromConfig(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value)
            ? (self::tryFrom($value) ?? self::Public)
            : self::Public;
    }
}
