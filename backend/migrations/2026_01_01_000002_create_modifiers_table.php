<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One answer within a modifier group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->json('title');

            // Added to the line price; may be negative.
            //
            // NOTHING PRICES THIS TODAY. `validateCart` prices a line from the Product and
            // its variant and has no concept of a surcharge, so a non-zero delta is stored,
            // shown to the customer and then not charged. Whether paid add-ons are wanted at
            // all is RESTAURANT-THEME-SPEC.md §17.3, and the two honest routes are §2.3
            // approach A (each paid modifier is its own Product on its own cart line) or
            // approach C (core prices declared modifiers, §14 item 4). Until one is chosen,
            // keep every modifier at 0.00 or accept that the delta is presentational.
            $table->decimal('price_delta', 10, 2)->default(0);

            // Set when this modifier is itself a sellable Product — the seam approach A needs.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->integer('orders')->nullable();
            $table->json('data')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifiers');
    }
};
