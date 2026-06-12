<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Validation;

/**
 * Impostor: a userland class that happens to share the Validator facade's short name. The
 * inline-validation scan must not mistake an import of this class for the Laravel facade.
 */
class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function make(array $data, array $options): array
    {
        return $data + $options;
    }
}
