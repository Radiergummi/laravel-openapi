<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Attributes\Response;

/**
 * Fixture controller whose action injects {@see UnknownRuleFormRequest}, causing generation to
 * emit a route-scoped `rule.unknown` finding. Used to verify `--path` scopes extractor-emitted
 * findings (#50).
 */
final class UnknownRuleController
{
    /**
     * Store an item.
     *
     * Persists an item validated by an un-introspectable rule.
     */
    #[Response(status: 200, description: 'Stored')]
    public function store(UnknownRuleFormRequest $request): array
    {
        return ['ok' => true];
    }
}
