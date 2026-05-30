<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Support\SpecTime;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Stringable;
use Traversable;

/**
 * A permissive stand-in value used while introspecting consumer code that expects an HTTP request
 * context — typically a {@see \Illuminate\Foundation\Http\FormRequest}'s `rules()` body reading
 * `$this->route('foo')->bar` or `$this->user()->customer_id`.
 *
 * Every magic-method path either returns the same singleton or an empty/zero value, so chains of
 * arbitrary depth resolve without throwing. The stub is meaningless as a value, but the rules
 * array's *structure* (keys, types, required-ness) is what the schema generator reads.
 *
 * Limitation: `rules()` bodies that branch on runtime state (`if ($this->user()->isAdmin())`)
 * will take the truthy branch (PHP's bool-cast of any non-null object). The spec then reflects
 * the truthy branch's rules; the falsy branch is not introspected. Document the FormRequest with
 * a non-branching `rules()` body, or accept the limitation and ship the truthy branch's schema.
 *
 * @internal Not part of the public extension surface.
 *
 * @implements ArrayAccess<mixed, mixed>
 * @implements IteratorAggregate<mixed, mixed>
 *
 * @method self whatever()
 * @method self withArgs(mixed ...$args)
 * @method self chained()
 * @method self calls()
 * @method self terminate()
 * @method self somewhere()
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

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): self
    {
        return $this;
    }

    public function __toString(): string
    {
        return '';
    }

    public function jsonSerialize(): mixed
    {
        return null;
    }

    public function count(): int
    {
        return 0;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator([]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return false;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // No-op: writes are not meaningful on a stub.
    }

    public function offsetUnset(mixed $offset): void
    {
        // No-op: writes are not meaningful on a stub.
    }
}
