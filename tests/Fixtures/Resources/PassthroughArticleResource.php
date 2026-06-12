<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Article;

/**
 * No toArray() override — the passthrough base case (folded #98): the response is
 * the wrapped model's schema.
 *
 * @mixin Article
 */
class PassthroughArticleResource extends JsonResource {}
