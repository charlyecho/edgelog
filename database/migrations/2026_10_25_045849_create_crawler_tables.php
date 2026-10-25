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
        // What each crawler, assistant and bot of a day asked for and cost:
        // the 50 busiest names of each group, the rest in one row.
        Schema::create('site_day_crawlers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_day_id')->constrained()->cascadeOnDelete();
            $table->string('group', 16);
            $table->string('name');
            $table->unsignedInteger('requests');
            $table->unsignedBigInteger('bytes');
            $table->unsignedBigInteger('time_ms');
            $table->unsignedInteger('timed');
            $table->json('times');
            $table->unsignedInteger('errors');
            $table->unsignedInteger('paths');
            $table->timestamp('last_seen_at');

            $table->index(['site_day_id', 'group']);
        });

        // The site's robots.txt, as last read from outside.
        Schema::create('site_robots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->unsignedSmallInteger('status')->nullable();
            $table->text('body')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('fetched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_robots');
        Schema::dropIfExists('site_day_crawlers');
    }
};
