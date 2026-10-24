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
        Schema::table('site_days', function (Blueprint $table) {
            $table->unsignedBigInteger('signed_in_users')->default(0)->after('cited_visits');
            $table->unsignedBigInteger('signed_in_readers')->default(0)->after('signed_in_users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_days', function (Blueprint $table) {
            $table->dropColumn(['signed_in_users', 'signed_in_readers']);
        });
    }
};
