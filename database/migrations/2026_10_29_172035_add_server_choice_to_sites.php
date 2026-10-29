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
        // A server picked by hand stays the site's, whatever machine its shipper names.
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('server_pinned')->default(false)->after('server_id');
        });

        // A name given by hand, shown in place of the host name.
        Schema::table('servers', function (Blueprint $table) {
            $table->string('name')->nullable()->after('machine_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('name');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('server_pinned');
        });
    }
};
