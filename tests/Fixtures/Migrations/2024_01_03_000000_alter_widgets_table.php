<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table): void {
            // Alter flow: a ->change() chain is Tier-2 territory, skipped entirely.
            $table->string('name', 250)->change();

            // A plain add on an existing table is read like any create() column.
            $table->string('slug', 64);
        });
    }
};
