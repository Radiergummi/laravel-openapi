<?php

declare(strict_types=1);

namespace Examples\Combined\Requests;

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
