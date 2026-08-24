<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A table held for one party over one span of time (register O19, phase 2).
 *
 * **Why a row and not a flag on the table.** Availability is a property of a *moment*, not of a
 * table: "table 7 is busy" is meaningless without saying when, and a boolean would be wrong the
 * instant a booking was cancelled by any path that forgot to clear it. The question this table
 * answers is a range overlap — *is anything already holding table 7 between 19:00 and 20:00* —
 * which is why it cannot live in `order.meta`: a JSON path cannot be indexed, and this query
 * runs on the checkout path.
 *
 * **Today is never bookable**, by the rule the operator set: the earliest booking is a whole
 * number of days ahead (default 1). That is what makes the floor manageable — everybody dining
 * today is a walk-in, so a reservation can never be in dispute with a party already sitting at
 * the table. Enforced in the theme's own writer and settings, not here; this table simply never
 * receives a same-day row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_bookings', function (Blueprint $table) {
            $table->id();

            // The order that holds it. Cascade: a hard-deleted order was never a real booking,
            // and leaving the row would hold a table for nobody.
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('outlet_table_id')->constrained('outlet_tables')->cascadeOnDelete();

            // The span. Stored rather than derived from `starts_at` + a duration setting,
            // because the setting can change tomorrow and a booking already made must keep the
            // span it was made under — the same rule an order's line prices follow.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // `booked` while it holds the table; anything else releases it. A cancelled order
            // frees its table through this column rather than by deleting the row, so the
            // history of who held what survives the cancellation.
            $table->string('status')->default('booked');

            // How many people it was booked for. Recorded because the table it was given was
            // chosen to fit them, and a counter looking at tonight's book needs to know.
            $table->unsignedSmallInteger('covers')->nullable();

            $table->timestamps();

            // The overlap query's index: every read is "this table, around this time".
            // `starts_at` leads because the range scan is on it.
            $table->index(['outlet_table_id', 'starts_at']);

            // Answering "what is this order holding" — the cancellation path's question.
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_bookings');
    }
};
