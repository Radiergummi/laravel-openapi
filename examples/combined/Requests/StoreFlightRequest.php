<?php

declare(strict_types=1);

namespace Examples\Combined\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Attributes\RequestField;

/**
 * Validation contract for `POST /flights` in the combined flavor.
 *
 * Demonstrates the FormRequest input path: the controller signature names
 * this class, the package derives the request-body schema from its rules,
 * and the `#[RequestField]` annotations enrich the schema with documentation
 * that pure validation rules cannot express.
 */
final class StoreFlightRequest extends FormRequest
{
    #[RequestField(description: 'IATA-style flight number, e.g., LH123.', example: 'LH123', pattern: '^[A-Z]{2}\\d{1,4}$')]
    public const string PARAM_NUMBER = 'number';

    #[RequestField(description: 'Three-letter IATA code of the origin airport.', example: 'FRA')]
    public const string PARAM_ORIGIN = 'origin';

    #[RequestField(description: 'Three-letter IATA code of the destination airport.', example: 'JFK')]
    public const string PARAM_DESTINATION = 'destination';

    #[RequestField(description: 'Scheduled departure timestamp.', format: 'date-time', example: '2026-08-01T09:00:00Z')]
    public const string PARAM_DEPARTS_AT = 'departs_at';

    #[RequestField(description: 'Scheduled arrival timestamp; must be after departs_at.', format: 'date-time', example: '2026-08-15T17:30:00Z')]
    public const string PARAM_ARRIVES_AT = 'arrives_at';

    #[RequestField(description: 'Operational status of the flight.', enum: ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled'])]
    public const string PARAM_STATUS = 'status';

    #[RequestField(description: 'Aircraft model / type designator.', example: 'Boeing 747-400')]
    public const string PARAM_AIRCRAFT_TYPE = 'aircraft_type';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            self::PARAM_NUMBER        => ['required', 'string', 'regex:/^[A-Z]{2}\d{1,4}$/'],
            self::PARAM_ORIGIN        => ['required', 'string', 'size:3', 'uppercase'],
            self::PARAM_DESTINATION   => ['required', 'string', 'size:3', 'uppercase'],
            self::PARAM_DEPARTS_AT    => ['required', 'date'],
            self::PARAM_ARRIVES_AT    => ['required', 'date', 'after:departs_at'],
            self::PARAM_STATUS        => ['required', 'in:scheduled,boarding,departed,arrived,cancelled'],
            self::PARAM_AIRCRAFT_TYPE => ['required', 'string'],
        ];
    }
}
