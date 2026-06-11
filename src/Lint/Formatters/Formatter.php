<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Formatters;

use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\OutputInterface;

interface Formatter
{
    public function render(LintResult $result, OutputInterface $output): void;
}
