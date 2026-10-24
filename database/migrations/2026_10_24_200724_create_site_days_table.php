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
        Schema::create('site_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('timezone');
            $table->string('status');
            $table->json('problems')->nullable();
            $table->unsignedBigInteger('parsed')->default(0);
            $table->unsignedBigInteger('unparsed')->default(0);
            $table->unsignedBigInteger('hits')->default(0);
            $table->unsignedBigInteger('other_hosts')->default(0);
            $table->unsignedBigInteger('unfiltered')->default(0);
            $table->unsignedBigInteger('cited_visits')->default(0);
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamp('partial_from')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'day']);
        });

        Schema::create('site_day_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_day_id')->constrained()->cascadeOnDelete();
            $table->string('group');
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('visits')->default(0);
            $table->unsignedBigInteger('readers')->default(0);
            $table->unsignedBigInteger('probes')->default(0);

            $table->unique(['site_day_id', 'group']);
        });

        Schema::create('site_day_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_day_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->unsignedBigInteger('count');

            $table->unique(['site_day_id', 'kind', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_day_counts');
        Schema::dropIfExists('site_day_groups');
        Schema::dropIfExists('site_days');
    }
};
