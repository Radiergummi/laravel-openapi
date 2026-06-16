<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\IdentifierCase;
use TypeError;
use ValueError;

use function preg_match;
use function sprintf;

/**
 * Base class for naming-convention lint rules.
 *
 * Accepts either an {@see IdentifierCase} enum or the raw config string from
 * `openapi.lint.style.*`; {@see IdentifierCase::fromConfig()} normalises both forms.
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

    #[Override]
    final public function severity(): Severity
    {
        return Severity::Inconsistent;
    }

    #[Override]
    abstract public function description(): string;

    /**
     * Whether the given name conforms to the configured case.
     */
    protected function conforms(string $name): bool
    {
        return preg_match($this->case->pattern(), $name) === 1;
    }

    /**
     * Human-readable fix hint for the given noun phrase.
     *
     * @param string $noun e.g., "field names", "path segments", "operationId"
     */
    protected function fixHint(string $noun): string
    {
        return sprintf(
            'Use %s for %s (e.g., %s).',
            $this->case->label(),
            $noun,
            $this->case->example(),
        );
    }
}
