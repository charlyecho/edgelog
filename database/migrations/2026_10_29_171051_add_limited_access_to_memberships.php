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
        // A viewer may be limited to the sites and servers granted one by one.
        Schema::table('team_user', function (Blueprint $table) {
            $table->boolean('limited')->default(false)->after('role');
        });

        Schema::create('membership_site', function (Blueprint $table) {
            $table->foreignId('membership_id')->constrained('team_user')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            $table->primary(['membership_id', 'site_id']);
        });

        Schema::create('membership_server', function (Blueprint $table) {
            $table->foreignId('membership_id')->constrained('team_user')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();

            $table->primary(['membership_id', 'server_id']);
        });

        // The same, set on the invitation and given when it is accepted.
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->boolean('limited')->default(false)->after('role');
            $table->json('site_ids')->nullable()->after('limited');
            $table->json('server_ids')->nullable()->after('site_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropColumn(['limited', 'site_ids', 'server_ids']);
        });

        Schema::dropIfExists('membership_server');
        Schema::dropIfExists('membership_site');

        Schema::table('team_user', function (Blueprint $table) {
            $table->dropColumn('limited');
        });
    }
};
