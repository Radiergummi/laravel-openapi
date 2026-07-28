<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('conflicted_articles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code', 40);
            $table->macAddress('device');
            $table->string('tags', 60);
            $table->unsignedInteger('score');
            $table->string('slug', 20)->nullable();
        });
    }
};
