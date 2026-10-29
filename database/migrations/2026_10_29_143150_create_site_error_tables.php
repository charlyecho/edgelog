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
        // Each error log a shipper sends (edgelog-ship --errors): the last batch
        // kept, and the entry still being written, held until the next one starts.
        Schema::create('site_error_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('shipper', 64);
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->longText('tail')->nullable();
            $table->timestamp('tail_at')->nullable();

            $table->unique(['site_id', 'kind', 'shipper']);
        });

        // One error, its occurrences grouped by their fingerprint; kept until the site goes.
        Schema::create('site_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 40);
            $table->string('kind', 20);
            $table->string('level', 20);
            $table->string('class')->nullable();
            $table->text('message');
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('regressed_at')->nullable();
            $table->unsignedInteger('times_seen')->default(0);
            $table->longText('last_stack')->nullable();

            $table->unique(['site_id', 'fingerprint']);
            $table->index(['site_id', 'status', 'last_seen_at']);
        });

        // Each time an error was logged, with the request it answered when it is known, kept 30 days.
        Schema::create('site_error_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_error_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('at');
            $table->string('kind', 20);
            $table->text('message');
            $table->string('method', 10)->nullable();
            $table->string('path', 2000)->nullable();
            $table->string('host')->nullable();
            $table->string('referrer', 2000)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('address', 45)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('request_from', 10)->nullable();
            $table->timestamp('looked_up_at')->nullable();
            $table->longText('stack')->nullable();

            $table->index(['site_error_id', 'at']);
            $table->index(['site_id', 'at']);
            $table->index(['site_id', 'looked_up_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_error_occurrences');
        Schema::dropIfExists('site_errors');
        Schema::dropIfExists('site_error_streams');
    }
};
