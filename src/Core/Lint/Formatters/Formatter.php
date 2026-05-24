<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Formatters;

use Radiergummi\OpenApi\Core\Lint\Finding;
use Symfony\Component\Console\Output\OutputInterface;

interface Formatter
{
    /**
     * @param list<Finding> $findings
     */
    public function render(array $findings, int $level, int $exitCode, OutputInterface $output): void;
}
