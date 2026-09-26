<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified long-running operation record (B5 / request #13).
 *
 * Two subsystems already did this well — component installs and panel updates
 * each have a table, a queued job, a broker-side OperationLogger and a page that
 * polls for progress. Everything else long-running did not: a docker build, an
 * Octane/PM2/Docker enable, a backup or restore, a mail domain enable all ran
 * synchronously behind a blocking HTTP request, with a 900-second timeout, no
 * progress, no log, no cancel and no record that they happened.
 *
 * This is the generalised home for those, keyed by subject so two different vhosts
 * can work at once while one vhost cannot work on itself twice.
 *
 * Deliberately not a progress percentage: the broker reports log lines, not steps,
 * so any number here would be invented. `step` carries the latest line instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();

            // What is being done, and to what. subject_* is a plain pair rather
            // than a polymorphic relation: the subjects are config-file-backed
            // (vhosts, components) and have no Eloquent identity of their own.
            $table->string('kind', 64);
            $table->string('subject_type', 24);
            $table->string('subject_id', 253);

            $table->string('broker_action', 64);
            $table->json('args')->nullable();
            $table->json('options')->nullable();

            $table->string('status', 16)->default('queued');
            $table->string('step', 255)->nullable();
            $table->text('log')->nullable();
            // Broker-side log file, tailed while the operation runs.
            $table->string('log_path', 512)->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
