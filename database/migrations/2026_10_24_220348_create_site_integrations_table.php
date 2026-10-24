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
        Schema::create('site_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('tool');
            $table->text('secret');
            $table->timestamp('last_received_at')->nullable();
            $table->timestamp('last_refused_at')->nullable();
            $table->string('last_refusal')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'tool']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_integrations');
    }
};
