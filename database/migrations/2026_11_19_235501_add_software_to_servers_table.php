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
        // What runs on the machine, as edgelog-ship --metrics last said it
        // (once an hour), each program judged against its end of life and
        // its known flaws once a day and whenever it changes.
        Schema::table('servers', function (Blueprint $table) {
            $table->json('software')->nullable()->after('hostname');
            $table->timestamp('software_read_at')->nullable()->after('software');
            $table->timestamp('software_checked_at')->nullable()->after('software_read_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['software', 'software_read_at', 'software_checked_at']);
        });
    }
};
