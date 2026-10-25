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
        // Visits that met a page not found or sent a form, per row.
        Schema::table('site_day_engagements', function (Blueprint $table) {
            $table->unsignedInteger('not_found')->default(0);
            $table->unsignedInteger('posted')->default(0);
        });

        // The pages each reader read that day: retention can follow those who read two or more.
        Schema::table('site_visitor_days', function (Blueprint $table) {
            $table->unsignedInteger('views')->default(0);
        });

        // Each page of a site the first day it was seen: read by a person,
        // fetched by Google. Its publish date is the earlier of the two.
        Schema::create('site_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->date('first_read_on')->nullable();
            $table->date('first_google_on')->nullable();

            $table->unique(['site_id', 'path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_pages');

        Schema::table('site_visitor_days', function (Blueprint $table) {
            $table->dropColumn('views');
        });

        Schema::table('site_day_engagements', function (Blueprint $table) {
            $table->dropColumn(['not_found', 'posted']);
        });
    }
};
