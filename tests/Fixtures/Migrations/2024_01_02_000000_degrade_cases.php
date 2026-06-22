<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Dynamic table name: the whole block is skipped.
        $name = 'dynamic_' . 'table';

        Schema::create($name, function (Blueprint $table): void {
            $table->uuid('id');
        });

        Schema::create('degrades', function (Blueprint $table): void {
            // Dynamic column name: skipped, the literal sibling still reads.
            $columnName = 'computed';
            $table->uuid($columnName);

            // Non-literal enum member: the enum is dropped, no contribution.
            $table->enum('kind', [SomeEnum::Alpha->value, 'beta']);

            // Off-whitelist macro: no contribution.
            $table->geometry('shape');

            // Still readable alongside the skipped shapes above.
            $table->uuid('reference');
        });
    }
};
