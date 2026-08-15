<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which groups are asked about which dish.
 *
 * This is a table rather than a JSON bag on the product because a group is authored once and
 * attached to many dishes — that reuse is the whole reason the group exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dish_modifier_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();

            // Display order of the questions on the dish sheet.
            $table->integer('orders')->nullable();

            // Overrides the group's own min_select for this dish only. Null = inherit.
            $table->boolean('required_override')->nullable();

            $table->timestamps();

            $table->unique(['product_id', 'modifier_group_id'], 'dish_modifier_group_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dish_modifier_group');
    }
};
