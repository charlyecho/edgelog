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
        // The site's icon on the local disk, read from the site or uploaded,
        // and when it last changed (it versions the icon's address).
        Schema::table('sites', function (Blueprint $table) {
            $table->string('icon_path')->nullable();
            $table->boolean('icon_uploaded')->default(false);
            $table->timestamp('icon_changed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['icon_path', 'icon_uploaded', 'icon_changed_at']);
        });
    }
};
