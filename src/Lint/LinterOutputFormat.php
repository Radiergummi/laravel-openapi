<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

enum LinterOutputFormat: string
{
    case Json = 'json';
    case Markdown = 'markdown';
    case Cli = 'cli';
    case GitHub = 'github';
    case Cobertura = 'cobertura';
    case Lcov = 'lcov';
}
