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
        // The team's uploaded icon on the local disk, and when it last
        // changed (it versions the icon's address).
        Schema::table('teams', function (Blueprint $table) {
            $table->string('icon_path')->nullable();
            $table->timestamp('icon_changed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['icon_path', 'icon_changed_at']);
        });
    }
};
