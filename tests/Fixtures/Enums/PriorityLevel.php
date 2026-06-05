<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Enums;

/** Int-backed fixture enum for class-string enum resolution tests. */
enum PriorityLevel: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;
}
