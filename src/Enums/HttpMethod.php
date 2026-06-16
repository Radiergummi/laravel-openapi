<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Enums;

enum HttpMethod: string
{
    case Get = 'get';
    case Post = 'post';
    case Put = 'put';
    case Patch = 'patch';
    case Delete = 'delete';
    case Options = 'options';
    case Head = 'head';
    case Trace = 'trace';

    /**
     * Resolves from a raw HTTP verb (case-insensitive). Returns null for unknown verbs.
     */
    public static function fromString(string $method): ?self
    {
        return self::tryFrom(strtolower($method));
    }

    public function forDisplay(): string
    {
        return strtoupper($this->value);
    }
}
