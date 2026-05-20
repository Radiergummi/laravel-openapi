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
 * Validation contract for `POST /flights/{flight}/bookings`.
 */
final class StoreBookingRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'passenger_name' => ['required', 'string', 'min:1', 'max:200'],
            'seat'           => ['required', 'string', 'regex:/^\d{1,3}[A-Z]$/'],
        ];
    }
}
