<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Core\Support\SpecTime;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Override;
use Stringable;
use Traversable;

/**
 * Permissive stub used while calling a FormRequest's `rules()` outside of an HTTP context.
 * Every magic-method chain returns the same singleton; callers never throw. Only the rules
 * array's structure matters — the stub's value is meaningless.
 *
 * Branches on runtime state (`if ($this->user()->isAdmin())`) always take the truthy path.
 *
 * @internal
 *
 * @implements ArrayAccess<mixed, mixed>
 * @implements IteratorAggregate<mixed, mixed>
 */
final class AnyValue implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, Stringable
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function __get(string $name): self
    {
        return $this;
    }

    public function __set(mixed $offset, mixed $value): void
    {
        // No-op.
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): self
    {
        return $this;
    }

    public function __isset(string $name): bool
    {
        return true;
    }

    #[Override]
    public function __toString(): string
    {
        return '';
    }

    #[Override]
    public function jsonSerialize(): null
    {
        return null;
    }

    #[Override]
    public function count(): int
    {
        return 0;
    }

    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator([]);
    }

    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        return false;
    }

    #[Override]
    public function offsetGet(mixed $offset): self
    {
        return $this;
    }

    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        // No-op.
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        // No-op.
    }
}
