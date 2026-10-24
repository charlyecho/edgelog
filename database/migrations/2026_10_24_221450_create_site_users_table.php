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
        // Each signed-in user of a site the first time they were seen, with
        // where that visit came from: what their revenue will be credited to.
        Schema::create('site_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 128);
            $table->timestamp('first_seen_at');
            $table->date('last_seen_day');
            $table->string('first_source')->nullable();
            $table->string('first_referrer')->nullable();
            $table->string('first_landing')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'uid']);
        });

        // The users seen each day: users over any period are the distinct
        // ones of its days, never the sum of each day's.
        Schema::create('site_day_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_day_id')->constrained()->cascadeOnDelete();
            $table->string('uid', 128);
            $table->unsignedInteger('views')->default(0);

            $table->unique(['site_day_id', 'uid']);
            $table->index('uid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_day_users');
        Schema::dropIfExists('site_users');
    }
};
