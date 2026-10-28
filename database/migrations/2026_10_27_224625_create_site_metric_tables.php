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
        // What the site's server said about itself each minute (edgelog-ship --metrics), kept a week.
        Schema::create('site_metric_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('minute');
            $table->float('cpu')->nullable();
            $table->float('iowait')->nullable();
            $table->float('load1');
            $table->float('load5');
            $table->float('load15');
            $table->unsignedSmallInteger('cores');
            $table->unsignedBigInteger('memory_total');
            $table->unsignedBigInteger('memory_available');
            $table->unsignedBigInteger('swap_total');
            $table->unsignedBigInteger('swap_free');
            $table->unsignedBigInteger('disk_read')->nullable();
            $table->unsignedBigInteger('disk_write')->nullable();
            $table->unsignedBigInteger('network_in')->nullable();
            $table->unsignedBigInteger('network_out')->nullable();
            $table->unsignedInteger('uptime');
            $table->json('disks');

            $table->unique(['site_id', 'minute']);
        });

        // The minutes of each hour, averaged and at their peak, kept 400 days.
        Schema::create('site_metric_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('hour');
            $table->unsignedSmallInteger('samples');
            $table->float('cpu_average')->nullable();
            $table->float('cpu_peak')->nullable();
            $table->float('iowait_average')->nullable();
            $table->float('load_average');
            $table->float('load_peak');
            $table->unsignedSmallInteger('cores');
            $table->unsignedBigInteger('memory_total');
            $table->unsignedBigInteger('memory_used_average');
            $table->unsignedBigInteger('memory_used_peak');
            $table->unsignedBigInteger('swap_total');
            $table->unsignedBigInteger('swap_used_peak');
            $table->unsignedBigInteger('disk_read')->nullable();
            $table->unsignedBigInteger('disk_write')->nullable();
            $table->unsignedBigInteger('network_in')->nullable();
            $table->unsignedBigInteger('network_out')->nullable();
            $table->json('disks');

            $table->unique(['site_id', 'hour']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_metric_hours');
        Schema::dropIfExists('site_metric_minutes');
    }
};
