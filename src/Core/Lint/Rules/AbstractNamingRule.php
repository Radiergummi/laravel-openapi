<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Lint\IdentifierCase;
use TypeError;
use ValueError;

use function preg_match;
use function sprintf;

/**
 * Base class for naming-convention lint rules.
 *
 * Subclasses must supply a concrete {@see Rule::id()} implementation and their
 * own visitor method(s). The constructor accepts either an {@see IdentifierCase}
 * enum directly (the shape used by hand-written tests) or the raw string value
 * coming from `config('openapi.lint.style.*')`; container-injected subclasses
 * pass the config string through and {@see IdentifierCase::fromConfig()} normalises it.
 */
abstract readonly class AbstractNamingRule implements Rule
{
    protected IdentifierCase $case;

    /**
     * @throws TypeError
     * @throws ValueError
     */
    public function __construct(IdentifierCase|string $case)
    {
        $this->case = IdentifierCase::fromConfig($case);
    }

    final public function level(): int
    {
        return 3;
    }

    abstract public function description(): string;

    /**
     * Returns true when the given name conforms to the configured case.
     */
    protected function conforms(string $name): bool
    {
        return preg_match($this->case->pattern(), $name) === 1;
    }

    /**
     * Returns a human-readable fix hint for the given noun phrase.
     *
     * @param string $noun e.g. "field names", "path segments", "operationId"
     */
    protected function fixHint(string $noun): string
    {
        return sprintf(
            'Use %s for %s (e.g. %s).',
            $this->case->label(),
            $noun,
            $this->case->example(),
        );
    }
}
