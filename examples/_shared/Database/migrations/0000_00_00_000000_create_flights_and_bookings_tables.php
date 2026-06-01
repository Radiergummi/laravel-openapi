<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->string('origin', 3);
            $table->string('destination', 3);
            $table->dateTime('departs_at');
            $table->dateTime('arrives_at');
            $table->string('status');
            $table->string('aircraft_type');
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('flight_id')->constrained()->cascadeOnDelete();
            $table->string('passenger_name');
            $table->string('seat', 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('flights');
    }
};
