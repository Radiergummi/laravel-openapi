<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Unrelated;

/**
 * An unrelated class that merely shares the `JsonResponse` short name, used to prove the OO
 * construction matcher rejects anything not assignable to Illuminate's JsonResponse.
 */
final class JsonResponse
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public array $data = []) {}
}
