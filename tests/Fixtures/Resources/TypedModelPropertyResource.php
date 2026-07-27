<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\DatedArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\TypedPropertyArticle;

/**
 * Shape (A) over an Eloquent model the resource declares as a typed property. Deliberately carries
 * no `@mixin`, so the declared property is the only source that can type these fields, and pairs
 * the bare reads with their `->format(…)` forms over the same receiver.
 */
class TypedModelPropertyResource extends JsonResource
{
    public function __construct(
        private readonly DatedArticle $article,
        private readonly TypedPropertyArticle $legacy,
    ) {
        parent::__construct($article);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'published_at' => $this->article->published_at,
            'release_date' => $this->article->release_date,
            'summary' => $this->article->summary,
            // @phpstan-ignore property.notFound (the unknown attribute is the refusal case under test)
            'unknown_column' => $this->article->missing_column,
            'relation_hop' => $this->article->parent->published_at,
            'relation_single_hop' => $this->article->parent,
            'public_typed_property' => $this->legacy->slug,
            'both_sources_typed' => $this->legacy->legacyCode,
            'published_at_formatted' => $this->article->published_at->format(DATE_ATOM),
            'release_day' => $this->article->release_date->format('Y-m-d'),
            // @phpstan-ignore nullsafe.neverNull (reading nullability off the call is the case under test)
            'nullsafe_published' => $this->article->published_at?->format(DATE_ATOM),
            'dynamic_format' => $this->article->published_at->format($request->dateFormat),
            'formatted_non_date' => $this->article->summary->format(DATE_ATOM),
        ];
    }
}
