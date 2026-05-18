<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Names the resource class that an operation returns — the explicit
 * override consulted by `ResourceClassResolver` before return-type analysis.
 * Typically an ApiResource subclass.
 *
 * Use this whenever the controller method's return type is too loose to name
 * the resource — e.g. it returns `JsonResponse`, an `AnonymousResourceCollection`,
 * or the `JsonResource` base type.
 *
 * ```php
 * #[OpenApi\ResponseResource(CompanyResource::class)]
 * public function show(Company $company): CompanyResource { … }
 *
 * #[OpenApi\ResponseResource(ProjectResource::class, collection: true)]
 * public function archive(): ProjectCollection { … }
 * ```
 *
 * `collection` overrides cardinality detection. Leave it null to let the
 * resolver infer it from the return type (a collection class ⇒ collection).
 *
 * Class-level usage (e.g. on an invocable controller) applies to every action
 * on the controller. Method-level wins on conflict.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::TARGET_CLASS)]
final readonly class ResponseResource
{
    /**
     * @param class-string $class      The resource class (e.g. an ApiResource subclass) whose schema
     *                                 the response resolver will build. Resolution is plugin-driven
     *                                 via the registered ResourceClassResolver implementations.
     * @param null|bool    $collection True for collection envelope, false for single, null = auto-detect.
     */
    public function __construct(
        public string $class,
        public ?bool $collection = null,
    ) {}
}
