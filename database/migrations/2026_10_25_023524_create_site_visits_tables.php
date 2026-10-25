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
        // One anonymous row per visit that read a page, kept journey_days:
        // labels, pages and times only, never an address or a user agent.
        Schema::create('site_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_day_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->unsignedInteger('seconds');
            $table->unsignedInteger('pages');
            $table->string('source');
            $table->string('via')->default('');
            $table->string('campaign')->default('');
            $table->string('country', 16);
            $table->string('city', 80)->default('');
            $table->string('device', 16);
            $table->string('browser', 32);
            $table->string('system', 32);
            $table->json('trail');
            $table->boolean('not_found')->default(false);
            $table->boolean('posted')->default(false);
            $table->boolean('signed_in')->default(false);
            $table->boolean('returning')->nullable();

            $table->index(['site_day_id', 'started_at']);
        });

        // A keyed hash of each reader of a day (address and browser family),
        // kept returning_days, to tell a returning visitor from a new one.
        Schema::create('site_visitor_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_day_id')->constrained()->cascadeOnDelete();
            $table->char('visitor', 32);
            $table->unsignedInteger('visits');
            $table->string('source');

            $table->unique(['site_day_id', 'visitor']);
            $table->index('visitor');
        });

        // The day's visits by source type and by device: counts only, so a
        // period sums them and reads its rates and median from the sums.
        Schema::create('site_day_engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_day_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 16);
            $table->string('name');
            $table->unsignedInteger('visits');
            $table->unsignedInteger('views');
            $table->unsignedInteger('one_page');
            $table->json('seconds');

            $table->unique(['site_day_id', 'dimension', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_day_engagements');
        Schema::dropIfExists('site_visitor_days');
        Schema::dropIfExists('site_visits');
    }
};
