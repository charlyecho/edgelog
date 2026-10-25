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
        // A suspect row is an address and a browser: keep which browser it claimed to be.
        Schema::table('site_day_suspects', function (Blueprint $table) {
            $table->string('user_agent')->default('')->after('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_day_suspects', function (Blueprint $table) {
            $table->dropColumn('user_agent');
        });
    }
};
