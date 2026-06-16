<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Fractal;

use League\Fractal\Serializer\ArraySerializer;

/**
 * A serializer outside the three modelled FQCNs — used to pin the unknown-serializer refusal.
 * Matching is by exact class name, so subclassing a modelled serializer does not make it match.
 */
final class CustomSerializer extends ArraySerializer {}
