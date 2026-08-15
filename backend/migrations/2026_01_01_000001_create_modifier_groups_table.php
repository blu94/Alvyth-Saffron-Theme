<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reusable question asked about a dish — "Choose your side", "Add extras".
 *
 * Columns fold into this create migration per project convention: there are no live installs
 * of this theme to patch, so the original migration is edited rather than ALTERed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->json('title');
            $table->string('slug')->unique();
            $table->json('description')->nullable();

            // radio vs checkbox on the dish sheet.
            $table->enum('selection', ['single', 'multiple'])->default('single');

            // min_select = 1 is what makes a group required.
            $table->unsignedInteger('min_select')->default(0);

            // null = unlimited. Ignored when selection = single.
            $table->unsignedInteger('max_select')->nullable();

            $table->string('status')->default('active');
            $table->integer('orders')->nullable();
            $table->json('data')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_groups');
    }
};
