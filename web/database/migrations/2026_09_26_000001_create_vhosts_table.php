<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-vhost state projection (A44).
 *
 * Vhost state lives in the web-server config files as `# azerioid-managed`
 * comments, parsed by regex. That is deliberate and stays authoritative for how
 * traffic is served — it survives loss of the panel database. But it is flat,
 * already carries a dozen keys, and cannot hold history, so features needing
 * structured per-vhost state had nowhere to put it.
 *
 * This table is a **projection**, never the source of truth. It exists so the
 * panel can query, filter and hang child records off a vhost. The broker keeps
 * validating against the real config files; a row here must never be able to
 * influence a privileged decision.
 *
 * Deliberately absent: anything from a live probe. tls_status (issuer, expiry,
 * reachability) comes from CertProbe at read time and would be stale the moment it
 * was stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vhosts', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 253)->unique();

            // Serving shape, as parsed from the managed comment.
            $table->string('engine', 16)->nullable();
            $table->string('type', 16)->nullable();
            $table->string('runtime', 16)->nullable();
            $table->string('php_version', 8)->nullable();
            $table->string('docroot', 512)->nullable();
            $table->string('reverse_proxy', 255)->nullable();

            // Config-derived TLS intent only — never probe results.
            $table->string('tls_mode', 16)->nullable();
            $table->boolean('tls_enabled')->default(false);

            $table->boolean('readonly')->default(false);
            $table->boolean('enabled')->default(true);

            // Runtime specifics (octane_*/pm2_*/docker_*) and app detection, kept as
            // JSON because the set differs per runtime and grows with each one.
            $table->json('runtime_meta')->nullable();
            $table->json('app_detect')->nullable();
            $table->json('domains')->nullable();

            // Drift detection: the hash of the config file at last reconcile. A
            // mismatch means someone edited it outside the panel.
            $table->string('config_path', 512)->nullable();
            $table->string('config_sha256', 64)->nullable();
            $table->timestamp('reconciled_at')->nullable();

            $table->timestamps();

            $table->index('runtime');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vhosts');
    }
};
