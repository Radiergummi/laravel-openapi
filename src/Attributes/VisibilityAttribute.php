<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use LogicException;
use ReflectionClass;

use function in_array;
use function sprintf;

/**
 * Shared behaviour for the environment-scoped visibility attributes {@see Hide} and {@see Expose}:
 * the mutually-exclusive `only`/`except` filters and the environment-matching predicate.
 *
 * @internal
 */
abstract readonly class VisibilityAttribute
{
    /**
     * @param null|list<non-empty-string> $only
     * @param null|list<non-empty-string> $except
     *
     * @throws LogicException
     */
    public function __construct(
        public ?array $only = null,
        public ?array $except = null,
    ) {
        if ($only !== null && $except !== null) {
            throw new LogicException(
                sprintf(
                    '#[%s] cannot use both `only` and `except` — they are mutually exclusive.',
                    new ReflectionClass($this)->getShortName(),
                ),
            );
        }
    }

    /**
     * Whether the attribute's scope applies in the given environment.
     */
    public function appliesIn(string $environment): bool
    {
        if ($this->only === null && $this->except === null) {
            return true;
        }

        if ($this->only !== null) {
            return in_array($environment, $this->only, true);
        }

        // $except !== null by elimination
        return !in_array($environment, $this->except ?? [], true);
    }
}
