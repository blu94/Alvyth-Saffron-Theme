<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Folds `service_exceptions` into `service_windows`, so one module answers "when are we open".
 *
 * They were two tables because they are two shapes: a window recurs on a `day_of_week` and an
 * exception lands on a `date`. But an operator does not hold two concepts — they hold opening
 * hours, and the days that differ. Two modules meant two sidebar entries, two lists, and
 * having to know which screen a Tuesday public holiday belonged to.
 *
 * `kind` carries the distinction that used to be carried by the table name, and the columns
 * each kind does not use stay null. That is the honest cost of the merge: the row is wider
 * than either shape needed, and the form hides half its fields depending on the kind.
 *
 * **Rows are copied before the old table is dropped, in one migration.** A two-step where the
 * drop ships separately would leave an install that ran only the first half carrying both
 * copies, with nothing to say which was authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_windows', function (Blueprint $table) {
            // Existing rows are all recurring — that is all this table could hold — so the
            // default backfills them correctly without a separate UPDATE.
            $table->enum('kind', ['recurring', 'exception'])->default('recurring')->after('id');

            $table->date('date')->nullable()->after('kind');
            $table->enum('exception_type', ['closed', 'open', 'custom'])->nullable()->after('mode');
            $table->json('reason')->nullable()->after('exception_type');

            $table->index(['kind', 'date']);
        });

        // An exception has no weekday, so the column that was the point of the old table
        // has to give. Laravel 13 changes this natively; no doctrine/dbal needed.
        Schema::table('service_windows', function (Blueprint $table) {
            $table->unsignedTinyInteger('day_of_week')->nullable()->change();
        });

        if (! Schema::hasTable('service_exceptions')) {
            return;
        }

        // Soft-deleted exceptions are left behind deliberately. They are not on any screen,
        // and carrying tombstones across a merge only preserves the ability to restore a row
        // into a table that no longer explains where it came from.
        foreach (DB::table('service_exceptions')->whereNull('deleted_at')->orderBy('id')->cursor() as $row) {
            DB::table('service_windows')->insert([
                'kind'           => 'exception',
                'date'           => $row->date,
                'day_of_week'    => null,
                'opens_at'       => $row->opens_at,
                'closes_at'      => $row->closes_at,
                'exception_type' => $row->type,
                'reason'         => $row->reason,

                // `mode` is not null on this table and means nothing for a dated override,
                // which closes or opens the shop however it is being served.
                'mode'       => 'both',
                'status'     => $row->status,
                'data'       => $row->data,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        Schema::drop('service_exceptions');
    }

    public function down(): void
    {
        Schema::create('service_exceptions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
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

        foreach (DB::table('service_windows')->where('kind', 'exception')->orderBy('id')->cursor() as $row) {
            DB::table('service_exceptions')->insert([
                'date'       => $row->date,
                'opens_at'   => $row->opens_at,
                'closes_at'  => $row->closes_at,
                'type'       => $row->exception_type ?? 'closed',
                'reason'     => $row->reason,
                'status'     => $row->status,
                'data'       => $row->data,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        DB::table('service_windows')->where('kind', 'exception')->delete();

        Schema::table('service_windows', function (Blueprint $table) {
            $table->dropIndex(['kind', 'date']);
            $table->dropColumn(['kind', 'date', 'exception_type', 'reason']);
        });

        // Every surviving row is recurring again, so the column can be required once more.
        Schema::table('service_windows', function (Blueprint $table) {
            $table->unsignedTinyInteger('day_of_week')->nullable(false)->change();
        });
    }
};
