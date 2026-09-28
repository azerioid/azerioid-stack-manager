<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-target backup schedules with age-based retention (B6, ADR A52). The single global
 * schedule in settings stays as it was; these run alongside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 16);   // db | files | caddy | vhost
            $table->string('target', 253);       // database name, site directory, domain, or "caddy"
            $table->string('engine', 16)->nullable();
            $table->string('destination', 16)->default('local');
            $table->string('cadence', 16)->default('daily');
            $table->unsignedTinyInteger('hour')->default(3);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedSmallInteger('retention_days')->nullable(); // null: the global default
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['target_type', 'target', 'destination']);
        });
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->foreignId('schedule_id')->nullable()->after('id')->constrained('backup_schedules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_id');
        });
        Schema::dropIfExists('backup_schedules');
    }
};
