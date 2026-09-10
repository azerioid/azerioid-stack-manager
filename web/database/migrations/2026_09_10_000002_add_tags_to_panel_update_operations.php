<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_update_operations', function (Blueprint $table) {
            $table->string('target_tag', 64)->nullable()->after('to_commit');
            $table->string('from_tag', 64)->nullable()->after('target_tag');
            $table->string('to_tag', 64)->nullable()->after('from_tag');
        });
    }

    public function down(): void
    {
        Schema::table('panel_update_operations', function (Blueprint $table) {
            $table->dropColumn(['target_tag', 'from_tag', 'to_tag']);
        });
    }
};
