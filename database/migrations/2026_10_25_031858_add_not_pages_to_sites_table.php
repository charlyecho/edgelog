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
        // Path patterns the site's own pages call while they are read: a
        // request to one of them is part of a visit, never a page of it.
        Schema::table('sites', function (Blueprint $table) {
            $table->json('not_pages')->nullable()->after('timezone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('not_pages');
        });
    }
};
