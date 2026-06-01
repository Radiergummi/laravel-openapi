<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

interface FindingsCollector
{
    public function emit(Finding $finding): void;
}
