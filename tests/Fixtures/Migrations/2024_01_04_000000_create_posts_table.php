<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->uuid('id');
            $table->string('summary');
            $table->unsignedInteger('priority');
            $table->dateTime('archived_at')->nullable();
            // A migration default outranks the model's $attributes entry for the same column.
            $table->string('state')->default('published');
            $table->string('status');
            $table->string('name');
        });
    }
};
