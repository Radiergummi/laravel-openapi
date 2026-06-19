<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponentMigration;

/**
 * Reusable parameter component definitions in docblock form, two blocks in one docblock so the fixer
 * must remove only the targeted block. `RecordPath` reproduces exactly the path parameter inference
 * derives from the `{record}` route segment, so it is redundant; `KeptParam` is load-bearing.
 * Signature-only; never invoked.
 *
 * @OA\Parameter(
 *     parameter="RecordPath",
 *     name="record",
 *     in="path",
 *     required=true,
 *
 *     @OA\Schema(type="string")
 * )
 *
 * @OA\Parameter(
 *     parameter="KeptParam",
 *     name="kept",
 *     in="query",
 *     description="A description inference cannot derive.",
 *
 *     @OA\Schema(type="string")
 * )
 */
final class DocblockComponents {}
