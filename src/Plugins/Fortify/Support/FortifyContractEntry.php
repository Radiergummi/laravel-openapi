<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fortify\Support;

use OpenApi\Annotations as OA;

/**
 * One row of the {@see FortifyContractTable}: the stock request body and its public component name,
 * the stock success body and status, and the FQCN of the Fortify response contract that governs the
 * body (consulted by the customization gate — null when no contract governs it, e.g. the
 * password-confirmation status endpoint that returns JSON directly from its controller).
 *
 * `requestSchemaName` is a clean, framework-agnostic component name (e.g. `LoginRequest`) — it must
 * never leak Fortify/PHP/namespace internals into the public document.
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
