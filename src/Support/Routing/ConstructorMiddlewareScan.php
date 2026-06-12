<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use function array_unique;
use function array_values;
use function in_array;

/**
 * Result of one {@see ConstructorMiddlewareScanner} pass over a controller's constructor.
 *
 * Each entry is one matched `$this->middleware(...)` registration: the literal middleware names
 * plus the `only` / `except` action scoping it carries (null = unscoped). The two booleans carry
 * degrade evidence for the caller's generation-log notice: a registration was found but refused
 * (non-literal name or scope, unknown chain link), or found only inside a conditional context.
 *
 * @internal
 */
final readonly class ConstructorMiddlewareScan
{
    /**
     * @param list<array{names: list<string>, only: null|list<string>, except: null|list<string>}> $entries
     */
    public function __construct(
        public array $entries = [],
        public bool $unreadableCallDetected = false,
        public bool $conditionalCallDetected = false,
    ) {}

    /**
     * The middleware names that apply to the given action method, after `only` / `except`
     * scoping — the same semantics as Laravel's `ControllerDispatcher::methodExcludedByOptions()`.
     *
     * @return list<string>
     */
    public function middlewareForAction(string $actionMethod): array
    {
        $names = [];

        foreach ($this->entries as $entry) {
            if ($entry['only'] !== null && !in_array($actionMethod, $entry['only'], true)) {
                continue;
            }

            if ($entry['except'] !== null && in_array($actionMethod, $entry['except'], true)) {
                continue;
            }

            foreach ($entry['names'] as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }
}
