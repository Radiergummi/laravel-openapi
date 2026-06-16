<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fortify\Support;

use OpenApi\Annotations as OA;

/**
 * One row of the {@see FortifyContractTable}: request schema, success schema/status, and the
 * Fortify response contract governing the response body (null when none applies).
 *
 * `requestSchemaName` must be a clean, framework-agnostic component name (e.g., `LoginRequest`).
 *
 * @internal
 */
final readonly class FortifyContractEntry
{
    /**
     * @param ?non-empty-string $requestSchemaName Public component name for the request body; null when body-less.
     * @param ?class-string     $responseContract
     */
    public function __construct(
        public ?OA\Schema $requestSchema,
        public ?string $requestSchemaName,
        public ?string $responseContract,
        public int $successStatus,
        public ?OA\Schema $successSchema,
    ) {}
}
