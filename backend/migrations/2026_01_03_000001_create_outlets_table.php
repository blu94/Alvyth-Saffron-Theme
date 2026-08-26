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

            // Whether this branch has a dining room.
            //
            // Collection and delivery have been per branch since this table existed; dine-in was
            // shop-wide (*Restaurant → Offer Dine In*) and simply rode on collection, so a
            // takeaway kiosk with a counter and no seating was offered *Dine in* anyway — while
            // the dining-room repeater right beside this column was already asking that same
            // branch for its tables. One shop-wide switch could not answer a per-branch question.
            //
            // **Defaults to `false`, and that is a deliberate ruling rather than a safe-looking
            // default.** Everywhere else in this table the permissive answer is the default — a
            // branch collects, delivers, and serves the whole catalogue unless told otherwise.
            // Seating is the one exception, because the permissive answer here is a branch
            // claiming a dining room it may not have, and a diner sent to a table that does not
            // exist is a worse failure than a tile an operator has to switch on. The
            // consequence is stated plainly on the form and in `docs/outlets.md`: a shop already
            // running with *Offer Dine In* on loses the tile at every branch until each branch is
            // ticked. Nothing is deleted — the shop-wide switch and every table row are untouched.
            //
            // It narrows within collection rather than beside it: a dine-in order is recorded as
            // `fulfillment_type = pickup` (a diner needs no address and pays no delivery fee), and
            // the branch itself is chosen from core's pickup-method list. So a branch with no
            // collection cannot offer dine-in whatever this column says, and the readers compose
            // the two rather than treating this one as the whole answer.
            $table->boolean('offers_dine_in')->default(false);

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

        // Which dishes a branch does **not** serve. An EXCLUSION list, and the direction is the
        // whole design.
        //
        // It was an allow-list until 2026-08-25, gated by a `restricts_menu` switch: on meant the
        // branch served *only* what was ticked. Three things followed, all of them bad. An
        // operator who flipped the switch and saved had a branch selling nothing. A branch that
        // could not make two dishes out of two hundred had to tick a hundred and ninety-eight. And
        // every dish added to the catalogue afterwards was silently missing from that branch until
        // somebody remembered to tick it there too — the failure gets worse the longer the shop
        // runs, which is the worst shape a default can have. Measured on a seeded shop: a branch
        // whose list held four dishes showed four of eighty-nine, and eight of ten category pages
        // rendered empty.
        //
        // That is not how the trade does it. Toast, Square, Lightspeed and the delivery-platform
        // merchant portals all default an item to available **everywhere** and let an operator
        // switch it off at a location. The permissive answer has to be the default, because the
        // catalogue grows and the exceptions do not.
        //
        // So a row here means "this branch cannot make this dish". No rows means no restriction —
        // which is every branch until an operator says otherwise, and every shop that never opens
        // the screen. `restricts_menu` went with the allow-list: absence of exclusions **is**
        // "serves everything", so there was nothing left for a switch to say, and a switch that
        // says nothing is the dead setting this project has already removed once (audit A8).
        //
        // A pivot rather than a column on `products`, because the relationship is many-to-many:
        // the same dish is unavailable at several branches, and a column could express only one.
        Schema::create('outlet_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();

            // No foreign key to `products`: that table is core's, a theme is uninstalled by
            // deleting its own tables, and a constraint pointing into core would make this
            // theme's removal fail on a shop that still has products. The repository checks the
            // product exists instead.
            $table->unsignedBigInteger('product_id')->index();

            // A branch may price a dish differently — the same nasi lemak costs more in KLCC than
            // in the suburb. Null means "the dish's own price", which is what every row starts as.
            //
            // **Note this now sits on an EXCLUSION row, which is a contradiction**: a dish the
            // branch does not serve cannot also carry that branch's price for it. Left in place
            // only because nothing reads it yet (register phase 4); whoever builds per-branch
            // pricing must give it its own pivot rather than overload this one, or the first row
            // that carries both will mean two opposite things at once.
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
