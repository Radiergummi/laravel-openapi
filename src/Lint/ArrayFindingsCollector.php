<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use Override;

final class ArrayFindingsCollector implements FindingsCollector
{
    /** @var list<Finding> */
    private array $findings = [];

    #[Override]
    public function emit(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    /** @return list<Finding> */
    public function all(): array
    {
        return $this->findings;
    }
}
