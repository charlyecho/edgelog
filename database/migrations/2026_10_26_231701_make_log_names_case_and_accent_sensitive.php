<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The columns that keep names read from logs or sent by a tool, under a
     * unique key: [table => [column => length]].
     */
    private const array COLUMNS = [
        'site_day_counts' => ['name' => 255],
        'site_day_engagements' => ['name' => 255],
        'site_pages' => ['path' => 255],
        'site_users' => ['uid' => 128],
        'site_day_users' => ['uid' => 128],
        'conversions' => ['external_ref' => 255],
        'hook_events' => ['event_id' => 255],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL's default collation finds "ClaudeBot" and "claudebot", or
        // "Québec" and "Quebec", the same: two rows of a day then break its
        // unique key. Compare them byte for byte, as SQLite does.
        $this->collate('utf8mb4_bin');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->collate('utf8mb4_unicode_ci');
    }

    private function collate(string $collation): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $collation) {
                foreach ($columns as $column => $length) {
                    $blueprint->string($column, $length)->collation($collation)->change();
                }
            });
        }
    }
};
