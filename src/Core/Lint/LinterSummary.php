<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Override;

use function array_fill;
use function array_keys;
use function count;
use function ksort;

final readonly class LinterSummary implements Arrayable, JsonSerializable
{
    /**
     * Total number of findings.
     */
    public int $total;

    /**
     * Finding counts per severity level, indexed by level.
     *
     * @var array<int, int>
     */
    public array $findingCountsPerLevel;

    /**
     * List of affected routes, indexed by route name.
     *
     * @var list<string>
     */
    public array $affectedRoutes;

    public int $affectedRoutesCount;

    public function __construct(
        /**
         * @var list<Finding>
         */
        public array $findings,
        public int $level,
    ) {
        $this->total = count($findings);

        $counts = array_fill(0, $level + 1, 0);

        foreach ($findings as $finding) {
            $counts[$finding->level] = ($counts[$finding->level] ?? 0) + 1;
        }

        ksort($counts);
        $this->findingCountsPerLevel = $counts;

        $routes = [];

        foreach ($findings as $finding) {
            $identifier = $finding->location->routeName ?? $finding->location->routeUri;

            if ($identifier !== null) {
                $routes[$identifier] = true;
            }
        }

        $this->affectedRoutes = array_keys($routes);
        $this->affectedRoutesCount = count($this->affectedRoutes);
    }

    #[Override]
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'by_level' => (object) $this->findingCountsPerLevel,
            'routes_affected' => $this->affectedRoutes,
        ];
    }

    #[Override]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
