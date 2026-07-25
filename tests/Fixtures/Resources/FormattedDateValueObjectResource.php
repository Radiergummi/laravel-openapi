<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Values\IssuedDocumentValue;

/**
 * Refuse: the wrapped class is a value object, not a Model. A bare `$this->issuedAt` still types
 * from its public property; formatting it does not.
 *
 * @mixin IssuedDocumentValue
 */
class FormattedDateValueObjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'issued_at' => $this->issuedAt->format(DATE_ATOM),
            'issued_at_raw' => $this->issuedAt,
        ];
    }
}
