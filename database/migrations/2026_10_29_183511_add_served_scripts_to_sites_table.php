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
        // The scripts the site serves its pages through (/index.php/shop),
        // found from its log: their paths are the site's own, never probes.
        Schema::table('sites', function (Blueprint $table) {
            $table->json('served_scripts')->nullable()->after('own_paths');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('served_scripts');
        });
    }
};
