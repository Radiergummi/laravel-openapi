<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Attributes\ResourceField;
use Radiergummi\OpenApi\Tests\Fixtures\Models\DatedArticle;

/**
 * `->format(…)` on a date-typed model attribute: one key per recognised format and per shape the
 * reader must keep refusing.
 *
 * @mixin DatedArticle
 */
#[ResourceField('declared_day', description: 'Declared, not inferred.', type: 'integer')]
class FormattedDateResource extends JsonResource
{
    public const string DAY_FORMAT = 'Y-m-d';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'atom_global' => $this->resource->created_at->format(DATE_ATOM),
            'atom_class_constant' => $this->created_at->format(DateTimeInterface::ATOM),
            'iso_c' => $this->published_at->format('c'),
            'rfc3339_extended' => $this->published_at->format(DATE_RFC3339_EXTENDED),
            'release_day' => $this->release_date->format('Y-m-d'),
            'published_day' => $this->published_at->format('Y-m-d'),
            'self_constant_day' => $this->published_at->format(self::DAY_FORMAT),
            'declared_day' => $this->published_at->format('Y-m-d'),
            'space_separated' => $this->published_at->format('Y-m-d H:i:s'),
            'legacy_iso' => $this->published_at->format(DATE_ISO8601),
            'expanded_iso' => $this->published_at->format(DATE_ISO8601_EXPANDED),
            'dynamic_format' => $this->published_at->format($request->dateFormat),
            'nullsafe_atom' => $this->updated_at?->format(DATE_ATOM),
            'conditional_atom' => $this->when(true, $this->published_at->format(DATE_ATOM)),
            'formatted_string' => $this->summary->format(DATE_ATOM),
            'formatted_unknown' => $this->missing_column->format(DATE_ATOM),
            'formatted_relation' => $this->parent->published_at->format(DATE_ATOM),
            'raw_created_at' => $this->created_at,
        ];
    }
}
