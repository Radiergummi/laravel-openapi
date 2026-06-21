<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Enums;

enum DescribedStatus: string
{
    /** The article is still being written. */
    case Draft = 'draft';

    /** The article is live. */
    case Published = 'published';
}
