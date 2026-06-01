<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

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
     * @param non-empty-string                          $name         Short name for the link — used as the map
     *                                                                key in `responses.{status}.links`. Must
     *                                                                match the naming constraints of component
     *                                                                object names (alphanumeric, `.`, `-`, `_`).
     * @param null|non-empty-string                     $operationId  The `operationId` of the target operation.
     *                                                                Mutually exclusive with `$operationRef`.
     * @param null|non-empty-string                     $operationRef A relative or absolute reference to the target
     *                                                                operation. Mutually exclusive with `$operationId`.
     * @param array<non-empty-string, non-empty-string> $parameters   Map of parameter name → runtime expression.
     * @param null|non-empty-string                     $description  Optional human-readable description of the link.
     */
    public function __construct(
        public string $name,
        public ?string $operationId = null,
        public ?string $operationRef = null,
        public array $parameters = [],
        public ?string $description = null,
    ) {}
}
