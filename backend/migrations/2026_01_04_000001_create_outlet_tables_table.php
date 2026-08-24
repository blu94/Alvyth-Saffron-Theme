<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables in a branch's dining room (register O19, phase 1).
 *
 * **Why a table and not a number.** Dine-in shipped with one shop-wide setting — *How Many
 * Tables* — that renders `Table 1..N` for every branch at once. That is expressible only for a
 * shop whose branches are identical: give Bangsar 20 and a diner at KLCC, which has 8, can pick
 * Table 15 and send the food to a table that does not exist; give it 8 and Bangsar's other
 * twelve tables are unreachable. Leaving it empty falls back to a free-text box, which accepts
 * any string including a wrong one — the exact risk the numbered list exists to remove.
 *
 * A table is a physical object standing in one room. It belongs to the outlet, and this table
 * says so.
 *
 * **`seats` is here for a question that could not previously be asked.** A four-seat table
 * cannot hold two parties of four, and until a table knows its capacity the system cannot say
 * so. Nothing in phase 1 reads it: the walk-in path does not need it, because a customer who is
 * already sitting down has settled the seating question themselves. It is filled in now so that
 * the booking half (phase 2) has a fact to work from rather than a migration to wait for, and it
 * is nullable because a shop that never takes bookings must not be made to count chairs.
 *
 * **Additive, like the outlets migration it follows.** It creates a new table and alters
 * nothing, so it runs on a live shop; an outlet with no rows here keeps exactly today's
 * behaviour through the existing shop-wide fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_tables', function (Blueprint $table) {
            $table->id();

            // Cascade, because a table cannot outlive the room it stands in. The same choice
            // `modifiers` makes about the group that owns them.
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();

            // **Deliberately not translatable**, and this is the same decision `outlets.slug`
            // made. A label is printed on the kitchen ticket, read out across a pass and spoken
            // by whoever carries the plate — one table, one name. A "Table 7" that renders as
            // "Meja 7" to the customer and "Table 7" to the kitchen is two names for one object,
            // and the moment they disagree the food goes to the wrong room.
            //
            // A string rather than an integer, because a dining room is not always numbered
            // 1..N: "A1", "Terrace 2" and "Booth by the window" are all real answers, and the
            // shape that cannot express them is what forced the free-text fallback.
            $table->string('label');

            // How many people it seats. Null means the shop has not said — see the docblock.
            $table->unsignedSmallInteger('seats')->nullable();

            // The theme's own conventions for every operator-managed row.
            $table->string('status')->default('active');
            $table->integer('orders')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Looked up one way only: every table in this branch, in the operator's order. The
            // storefront asks it once the customer has chosen a branch, and the admin asks it
            // when drawing the outlet's own form.
            $table->index(['outlet_id', 'status']);
        });

        // **No unique index on (outlet_id, label), and that is deliberate.** Two tables named 7
        // in one room is certainly an operator error, but these rows soft-delete: a unique index
        // would let a deleted "7" block the operator from ever creating "7" again, which is a
        // worse failure than the one it prevents and is invisible when it happens. Uniqueness is
        // enforced where it can see the difference — the repository, on save, the same place the
        // modifier repeater drops an answer with no text.
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_tables');
    }
};
