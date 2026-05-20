<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation contract for `PATCH /flights/{flight}`.
 *
 * Only mutable fields appear here. Each rule starts with `sometimes` so callers
 * can submit partial updates.
 */
final class UpdateFlightRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'status'        => ['sometimes', 'in:scheduled,boarding,departed,arrived,cancelled'],
            'aircraft_type' => ['sometimes', 'string'],
        ];
    }
}
