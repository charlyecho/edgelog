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
        // The address the server's last sample came from: where Edgelog
        // tries, from outside, the services it listens to on every interface.
        Schema::table('servers', function (Blueprint $table) {
            $table->string('public_address', 45)->nullable()->after('hostname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('public_address');
        });
    }
};
