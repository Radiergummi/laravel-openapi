<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Provenance;

use Radiergummi\OpenApi\Contracts\Registry\OperationConvention;

/**
 * A resolved {@see OperationConvention} paired with the resolver class that produced it, so
 * provenance can name the actual resolver rather than a hardcoded literal.
 *
 * @internal
 */
final readonly class ResolvedConvention
{
    /**
     * @param class-string $resolver
     */
    public function __construct(
        public OperationConvention $convention,
        public string $resolver,
    ) {}
}
