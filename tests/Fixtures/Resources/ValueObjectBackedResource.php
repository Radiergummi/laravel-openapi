<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function strtoupper;

/**
 * Wraps a typed value object (not a model) and reads its typed public properties through a declared
 * resource property (`$this->summary->field`). Exercises #411: the chain resolver types each field
 * from the value object's property types; a method call still degrades to unconstrained.
 */
class ValueObjectBackedResource extends JsonResource
{
    public function __construct(private readonly GenreSummaryValue $summary)
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
            'description' => $this->summary->description,
            'kind' => $this->summary->kind,
            'computed' => $this->computeLabel(),
        ];
    }

    private function computeLabel(): string
    {
        return strtoupper('label');
    }
}
