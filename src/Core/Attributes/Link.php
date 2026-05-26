<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Declares an OpenAPI Link on the operation's primary 2xx response so consumers can chain
 * operations — e.g. after `POST /projects` succeeds, the response `uuid` flows into `GET
 * /projects/{uuid}`. Repeatable. Exactly one of `operationId` or `operationRef` is required.
 *
 * `parameters` maps `parameterName => runtimeExpression`, where common expressions are
 * `$response.body#/data/uuid`, `$request.body#/name`, or `$url`.
 *
 * ```php
 * #[OpenApi\Link(
 *     name: 'GetProject',
 *     operationId: 'api.v0.projects.single',
 *     parameters: ['uuid' => '$response.body#/data/uuid'],
 * )]
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Link
{
    /**
     * @param string                $name         Short name for the link — used as the map key
     *                                            in `responses.{status}.links`. Must match the
     *                                            naming constraints of component object names
     *                                            (alphanumeric, `.`, `-`, `_`).
     * @param null|string           $operationId  The `operationId` of the target operation.
     *                                            Mutually exclusive with `$operationRef`.
     * @param null|string           $operationRef A relative or absolute reference to the target
     *                                            operation. Mutually exclusive with `$operationId`.
     * @param array<string, string> $parameters   Map of parameter name → runtime expression.
     * @param null|string           $description  Optional human-readable description of the link.
     */
    public function __construct(
        public string $name,
        public ?string $operationId = null,
        public ?string $operationRef = null,
        public array $parameters = [],
        public ?string $description = null,
    ) {}
}
