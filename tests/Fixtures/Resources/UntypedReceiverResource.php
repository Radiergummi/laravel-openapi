<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Refuse: the wrapped receiver `$this->summary` has no declared class type, so shape (A) cannot
 * resolve a value object and the field stays unconstrained.
 */
class UntypedReceiverResource extends JsonResource
{
    /** @phpstan-ignore missingType.property (the untyped receiver is the refusal case under test) */
    public $summary;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->summary->publicId,
        ];
    }
}
