<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('INTENTIONAL panel self-update rollback probe — do not keep on main.');
    }

    public function down(): void
    {
    }
};
