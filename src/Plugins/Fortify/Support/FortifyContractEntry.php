<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fortify\Support;

use OpenApi\Annotations as OA;

/**
 * One row of the {@see FortifyContractTable}: the stock request body, the stock success body and
 * status, and the FQCN of the Fortify response contract that governs the body (consulted by the
 * customization gate — null when no contract governs it, e.g. the password-confirmation status
 * endpoint that returns JSON directly from its controller).
 *
 * @internal
 */
final readonly class FortifyContractEntry
{
    /**
     * @param ?class-string $responseContract
     */
    public function __construct(
        public ?OA\Schema $requestSchema,
        public ?string $responseContract,
        public int $successStatus,
        public ?OA\Schema $successSchema,
    ) {}
}
