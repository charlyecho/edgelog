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
        // PHP-FPM's pools summed up and nginx, from their status pages (edgelog-ship
        // --fpm-status, --nginx-status): null for a server whose shipper reads neither.
        Schema::table('server_metric_minutes', function (Blueprint $table) {
            $table->unsignedInteger('fpm_active')->nullable();
            $table->unsignedInteger('fpm_total')->nullable();
            $table->unsignedInteger('fpm_queue')->nullable();
            $table->float('fpm_requests')->nullable();
            $table->unsignedInteger('fpm_saturated')->nullable();
            $table->unsignedInteger('fpm_slow')->nullable();
            $table->unsignedInteger('nginx_connections')->nullable();
            $table->float('nginx_requests')->nullable();
        });

        Schema::table('server_metric_hours', function (Blueprint $table) {
            $table->float('fpm_active_average')->nullable();
            $table->unsignedInteger('fpm_active_peak')->nullable();
            $table->unsignedInteger('fpm_total_peak')->nullable();
            $table->unsignedInteger('fpm_queue_peak')->nullable();
            $table->float('fpm_requests')->nullable();
            $table->unsignedInteger('fpm_saturated')->nullable();
            $table->unsignedInteger('fpm_slow')->nullable();
            $table->float('nginx_connections_average')->nullable();
            $table->unsignedInteger('nginx_connections_peak')->nullable();
            $table->float('nginx_requests')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_metric_minutes', function (Blueprint $table) {
            $table->dropColumn(['fpm_active', 'fpm_total', 'fpm_queue', 'fpm_requests', 'fpm_saturated', 'fpm_slow', 'nginx_connections', 'nginx_requests']);
        });

        Schema::table('server_metric_hours', function (Blueprint $table) {
            $table->dropColumn(['fpm_active_average', 'fpm_active_peak', 'fpm_total_peak', 'fpm_queue_peak', 'fpm_requests', 'fpm_saturated', 'fpm_slow', 'nginx_connections_average', 'nginx_connections_peak', 'nginx_requests']);
        });
    }
};
