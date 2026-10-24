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
        Schema::create('site_ingest_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('last_batch_at')->nullable();
            $table->unsignedInteger('last_batch_lines')->default(0);
            $table->unsignedInteger('last_batch_rejected')->default(0);
            $table->string('last_batch_shipper', 64)->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->date('today')->nullable();
            $table->unsignedBigInteger('today_lines')->default(0);
            $table->timestamp('last_refusal_at')->nullable();
            $table->unsignedSmallInteger('last_refusal_status')->nullable();
            $table->string('last_refusal_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_ingest_statuses');
    }
};
