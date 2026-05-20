<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                     $aircraft_type
 * @property \Illuminate\Support\Carbon $arrives_at
 * @property \Illuminate\Support\Carbon $departs_at
 * @property string                     $destination
 * @property string                     $id
 * @property string                     $number
 * @property string                     $origin
 * @property string                     $status
 */
final class Flight extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'departs_at' => 'datetime',
        'arrives_at' => 'datetime',
    ];

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
