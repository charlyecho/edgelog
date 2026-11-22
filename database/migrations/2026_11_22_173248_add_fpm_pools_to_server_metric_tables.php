<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Each PHP-FPM pool on its own, by its name (edgelog-ship 1.6.0): null for a
        // server whose shipper reads no status page, or is older.
        Schema::table('server_metric_minutes', function (Blueprint $table) {
            $table->json('fpm_pools')->nullable();
        });

        Schema::table('server_metric_hours', function (Blueprint $table) {
            $table->json('fpm_pools')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_metric_minutes', function (Blueprint $table) {
            $table->dropColumn('fpm_pools');
        });

        Schema::table('server_metric_hours', function (Blueprint $table) {
            $table->dropColumn('fpm_pools');
        });
    }
};
