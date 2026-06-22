<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Values\GenreSummaryValue;

/**
 * Shape (A): `$this-><wrappedProp>-><field>` against a typed non-Model value object.
 */
class ValueObjectResource extends JsonResource
{
    public function __construct(public GenreSummaryValue $summary)
    {
        parent::__construct($summary);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => 'genres',
            'id' => $this->summary->publicId,
            'name' => $this->summary->name,
            'song_count' => $this->summary->songCount,
            'length' => $this->summary->length,
            'note' => $this->summary->note,
            'mixed' => $this->summary->mixedKey,
            'absent' => $this->summary->somethingAbsent,
        ];
    }
}
