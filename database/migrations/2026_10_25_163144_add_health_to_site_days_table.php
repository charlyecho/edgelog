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
        // The site's own heartbeat that day (slots expected and answered, and
        // the gaps), and what can be seen of the log being whole (duplicates,
        // silences, lines in a short format).
        Schema::table('site_days', function (Blueprint $table) {
            $table->json('heartbeat')->nullable()->after('stylesheets');
            $table->json('quality')->nullable()->after('heartbeat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_days', function (Blueprint $table) {
            $table->dropColumn(['heartbeat', 'quality']);
        });
    }
};
