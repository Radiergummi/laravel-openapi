<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\PhpStan\Data;

use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;

final class QueryBuilderFilterDuplicateFixture
{
    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('created_after', type: 'string')]
    public function distinctNames(): void {}

    #[AllowedFilter('status', type: 'string')]
    #[AllowedFilter('status', type: 'integer')]
    public function duplicatePositional(): void {}

    #[AllowedFilter(name: 'state', type: 'string')]
    #[AllowedFilter('state', type: 'integer')]
    public function duplicateMixedNamedAndPositional(): void {}

    #[AllowedFilter('limit')]
    #[AllowedFilter('limit')]
    #[AllowedFilter('limit')]
    public function tripleDuplicate(): void {}

    #[AllowedFilter('status')]
    public function single(): void {}
}
