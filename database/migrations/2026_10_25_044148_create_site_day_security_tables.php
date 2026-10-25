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
        // A day's security, kept for good: its figures, each host's answers
        // to probes for files, and the answers worth a look (paths, never addresses).
        Schema::create('site_day_securities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_day_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('figures');
            $table->json('hosts');
            $table->json('exposures');
        });

        // The day's suspects (scanners, attackers, fake browsers), one row per
        // address and browser, worst first. Kept security.kept_days, for the block list.
        Schema::create('site_day_suspects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_day_id')->constrained()->cascadeOnDelete();
            $table->string('address', 45);
            $table->string('country', 2)->default('');
            $table->timestamp('first_seen_at');
            $table->unsignedInteger('requests');
            $table->unsignedInteger('probes');
            $table->unsignedInteger('attempts');
            $table->unsignedInteger('fake_requests');
            $table->json('categories');
            $table->json('answered');
            $table->json('paths');
            $table->json('signs');

            $table->index(['site_day_id', 'probes']);
            $table->index('address');
        });

        // Login hammering and bursts: who, when, how many, on what. Kept like the suspects.
        Schema::create('site_day_attacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_day_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('address', 45);
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->unsignedInteger('requests');
            $table->json('paths');
            $table->json('answered');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_day_attacks');
        Schema::dropIfExists('site_day_suspects');
        Schema::dropIfExists('site_day_securities');
    }
};
