<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

/** Backed enum property on {@see GenreSummaryValue}, to exercise leaf-type fidelity (component $ref). */
enum GenreKindValue: string
{
    case Music = 'music';

    case Spoken = 'spoken';
}
