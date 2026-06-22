<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->uuid('id');
            $table->foreignUuid('owner_id');
            $table->ulid('reference');
            $table->ipAddress('last_ip')->nullable();
            $table->macAddress('mac');
            $table->date('released_on');
            $table->dateTime('manufactured_at');
            $table->timestamp('shipped_at');
            $table->time('opens_at');
            $table->year('model_year');
            $table->json('configuration');
            $table->decimal('price', 8, 2);
            $table->string('name', 120);
            $table->char('code', 8);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('serial');
            $table->increments('legacy_id');
            $table->enum('size', ['small', 'medium', 'large']);
            $table->enum('status', ['draft', 'published']);
            $table->set('flags', ['a', 'b']);
            $table->string('label')->default('unlabelled');
            $table->integer('weight')->default(0);
            $table->boolean('active')->default(true);
            $table->string('nickname')->default(null);
            $table->string('notes')->comment('Free-form operator notes.');
            $table->string('expression')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->string('untouched');
        });
    }
};
