<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public holidays and one-off closures.
 *
 * Deliberately the same shape as the booking spec's `schedule_exceptions` — it is the same
 * problem, and forking it would drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_exceptions', function (Blueprint $table) {
            $table->id();
            $table->date('date');

            // Both null when type = closed.
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();

            $table->enum('type', ['closed', 'open', 'custom'])->default('closed');
            $table->json('reason')->nullable();

            $table->string('status')->default('active');
            $table->json('data')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_exceptions');
    }
};
