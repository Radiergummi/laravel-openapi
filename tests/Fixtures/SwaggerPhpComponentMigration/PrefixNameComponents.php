<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponentMigration;

/**
 * Two docblock response components whose names prefix-collide (`Ok` is a prefix of `OkResponse`), so
 * the fixer's name match must be end-anchored: removing `Ok` must leave `OkResponse` byte-identical.
 * Signature-only; never invoked.
 *
 * @OA\Response(
 *     response="Ok",
 *
 *     @OA\JsonContent(ref="#/components/schemas/PlainStructData")
 * )
 *
 * @OA\Response(
 *     response="OkResponse",
 *     description="A description inference cannot derive.",
 *
 *     @OA\JsonContent(ref="#/components/schemas/PlainStructData")
 * )
 */
final class PrefixNameComponents {}
