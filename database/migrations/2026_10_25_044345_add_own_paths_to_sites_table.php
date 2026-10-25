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
        // Path patterns the site really has that scanners also ask for: never a probe.
        Schema::table('sites', function (Blueprint $table) {
            $table->json('own_paths')->nullable()->after('not_pages');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('own_paths');
        });
    }
};
