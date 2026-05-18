<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Radiergummi\OpenApi\Core\Lint\IdentifierCase;

use function preg_match;
use function sprintf;

/**
 * Base class for naming-convention lint rules.
 *
 * Caches the regex pattern string from {@see IdentifierCase} so it is not
 * re-fetched on every node visit. Subclasses must supply a concrete
 * {@see Rule::id()} implementation and their own visitor method(s).
 */
abstract readonly class AbstractNamingRule implements Rule
{
    protected string $pattern;

    public function __construct(protected IdentifierCase $case)
    {
        $this->pattern = $case->pattern();
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
        return preg_match($this->pattern, $name) === 1;
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
