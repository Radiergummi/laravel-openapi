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
 * Declares an OpenAPI Link on the operation's primary 2xx response.
 *
 * Links let consumers understand how to chain operations — e.g. after
 * `POST /projects` succeeds, the `uuid` in the response body can be passed
 * directly to `GET /projects/{uuid}`. Scalar and Stoplight render these as
 * call-graph hints alongside the response.
 *
 * Multiple `#[Link]` attributes may be stacked on a single action.
 *
 * **Parameters** is a map of `parameterName => runtimeExpression` where a
 * runtime expression refers to a value extracted from the current response,
 * request, or URL. Common expressions:
 * - `$response.body#/data/uuid`  — field from the response body
 * - `$response.body#/data/id`    — numeric primary key in the response body
 * - `$request.body#/name`        — field echoed from the request body
 * - `$url`                       — the full request URL
 *
 * Exactly one of `operationId` or `operationRef` must be provided:
 * - `operationId` — references the `operationId` of the target operation in
 *   this same document; preferred for intra-document links.
 * - `operationRef` — a relative or absolute JSON Pointer to the target
 *   operation; use when the target is in a different document.
 *
 * ```php
 * // Minimal: link from POST /projects to GET /projects/{uuid}
 * #[OpenApi\Link(
 *     name: 'GetProject',
 *     operationId: 'api.v0.projects.single',
 *     parameters: ['uuid' => '$response.body#/data/uuid'],
 * )]
 * public function create(CreateProjectData $data): ProjectResource { … }
 *
 * // With description
 * #[OpenApi\Link(
 *     name: 'GetProject',
 *     operationId: 'api.v0.projects.single',
 *     parameters: ['uuid' => '$response.body#/data/uuid'],
 *     description: 'Retrieve the newly created project.',
 * )]
 *
 * // Using operationRef for cross-document links
 * #[OpenApi\Link(
 *     name: 'GetProject',
 *     operationRef: '#/paths/~1projects~1{uuid}/get',
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
