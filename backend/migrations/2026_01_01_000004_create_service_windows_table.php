<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the shop, or one menu section, is orderable.
 *
 * Times are stored in the SHOP's local wall-clock, not UTC. A 09:00 opening is 09:00 there
 * whatever the season — generate the window from local wall-clock and convert for display,
 * never the reverse, or a DST transition shifts every window by an hour.
 *
 * This table is presentation-only until core refuses an out-of-hours checkout
 * (RESTAURANT-THEME-SPEC.md §14 item 6). The storefront can grey out the menu; nothing stops
 * a direct POST to /storefront/checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_windows', function (Blueprint $table) {
            $table->id();

            // null / null = the whole shop. Otherwise a Category, e.g. a "Breakfast" section.
            $table->nullableMorphs('scope');

            // 0 = Sunday … 6 = Saturday. Several rows per day express split service.
            $table->unsignedTinyInteger('day_of_week');

            // Nullable because a `kind = exception` row that closes the day carries no hours
            // — the repository clears them on save so "Closed" can never also advertise an
            // opening. NOT NULL here once made that clear fail: saving a public holiday from
            // the admin threw 1364 on any strict-mode MySQL.
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();

            $table->enum('mode', ['delivery', 'pickup', 'both'])->default('both');

            $table->string('status')->default('active');
            $table->integer('orders')->nullable();
            $table->json('data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['day_of_week', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_windows');
    }
};
