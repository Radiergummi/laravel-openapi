<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Http\JsonResponse;

/**
 * An app subclass of Illuminate's JsonResponse, used to prove the OO construction matcher accepts
 * subclasses (`is_a(..., true)`).
 */
final class CustomJsonResponse extends JsonResponse {}
