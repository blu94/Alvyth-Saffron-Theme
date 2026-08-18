<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches of one shop (Q5 — "should allow several, and admin should be able to decide to have
 * multiple or single site").
 *
 * **This is deliberately NOT ROADMAP 36's multi-site.** That item scopes every table in core by
 * a `site_id` resolved from the incoming host — a migration project across the whole
 * application. What was actually asked for is narrower and lives entirely in this theme: one
 * shop, one catalogue, several places to collect from or be delivered by, and a picker the
 * customer meets **inside the pickup flow** rather than a separate site per branch.
 *
 * A shop with no outlets, or exactly one, behaves precisely as it does today: the storefront
 * renders no picker, and every existing query that does not mention an outlet keeps its meaning.
 * That is why this migration is purely additive — it creates a new table and alters nothing —
 * and why it can run on a live shop without a re-seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();

            // Translatable, like every other operator-authored name in this theme.
            $table->json('title');
            $table->string('slug')->unique();

            // Where it is, and how to reach it. `address` is the collection address a pickup
            // order shows; the footer's address stays the fallback for a shop with no outlets.
            $table->text('address')->nullable();
            $table->string('phone')->nullable();

            // Latitude and longitude, for the distance-radius delivery zones O7b calls for.
            // Nullable because a shop that never uses a radius should not be forced to geocode,
            // and because geocoding belongs at save time, never in the checkout path.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Which fulfilment modes this outlet offers. A branch may collect only, or deliver
            // only, without the shop having to switch the whole feature off.
            $table->boolean('offers_pickup')->default(true);
            $table->boolean('offers_delivery')->default(true);

            // "Admin can decide with branch having which catalogue." A null value here means
            // this outlet serves the whole menu, which is what a single-outlet shop and a newly
            // created branch both want — the operator opts INTO restricting, never out of it.
            // The dish list itself lives on the pivot; this is the switch that says whether the
            // pivot is consulted at all.
            $table->boolean('restricts_menu')->default(false);

            // The outlet a shop treats as its own default, for a customer who has not chosen.
            // Exactly one row should carry this; the repository enforces it rather than the
            // schema, because a unique index on a boolean would forbid the second `false`.
            $table->boolean('is_default')->default(false);

            $table->string('timezone')->nullable();
            $table->string('status')->default('active');
            $table->integer('orders')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'orders']);
        });

        // Which dishes an outlet serves, consulted only when `restricts_menu` is true.
        //
        // A pivot rather than a column on `products`, because the relationship is many-to-many:
        // the same dish is served by several branches, and a column could express only one.
        Schema::create('outlet_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();

            // No foreign key to `products`: that table is core's, a theme is uninstalled by
            // deleting its own tables, and a constraint pointing into core would make this
            // theme's removal fail on a shop that still has products. The repository checks the
            // product exists instead.
            $table->unsignedBigInteger('product_id')->index();

            // A branch may price a dish differently — the same nasi lemak costs more in KLCC
            // than in the suburb. Null means "the dish's own price", which is what every row
            // starts as, so adding an outlet never silently re-prices the menu.
            $table->decimal('price_override', 12, 2)->nullable();

            $table->timestamps();

            $table->unique(['outlet_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_product');
        Schema::dropIfExists('outlets');
    }
};
