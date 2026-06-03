<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Enums;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
