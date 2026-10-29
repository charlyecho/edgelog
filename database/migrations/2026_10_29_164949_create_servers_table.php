<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The figures of a minute, the same for a site or a server.
     */
    private const array MINUTE_COLUMNS = ['minute', 'cpu', 'iowait', 'load1', 'load5', 'load15', 'cores', 'memory_total', 'memory_available', 'swap_total', 'swap_free', 'disk_read', 'disk_write', 'network_in', 'network_out', 'uptime', 'disks'];

    /**
     * The figures of an hour, the same for a site or a server.
     */
    private const array HOUR_COLUMNS = ['hour', 'samples', 'cpu_average', 'cpu_peak', 'iowait_average', 'load_average', 'load_peak', 'cores', 'memory_total', 'memory_used_average', 'memory_used_peak', 'swap_total', 'swap_used_peak', 'disk_read', 'disk_write', 'network_in', 'network_out', 'disks'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A machine that runs edgelog-ship --metrics for one or more sites of a
        // team, known by a hash of its /etc/machine-id (null for a shipper too
        // old to send it, or the servers of the figures kept per site before).
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('machine_id', 64)->nullable();
            $table->string('hostname')->nullable();
            $table->timestamp('last_sample_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'machine_id']);
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('server_id')->nullable()->after('team_id')->constrained()->nullOnDelete();
        });

        // What the server said about itself each minute, kept a week.
        Schema::create('server_metric_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $this->minuteColumns($table);

            $table->unique(['server_id', 'minute']);
        });

        // The minutes of each hour, averaged and at their peak, kept 400 days.
        Schema::create('server_metric_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $this->hourColumns($table);

            $table->unique(['server_id', 'hour']);
        });

        // Each site that has figures gets a server of its own: the shipper
        // fills in its machine id with its next sample.
        $sites = DB::table('sites')
            ->whereIn('id', DB::table('site_metric_minutes')->select('site_id'))
            ->orWhereIn('id', DB::table('site_metric_hours')->select('site_id'))
            ->get(['id', 'team_id']);

        foreach ($sites as $site) {
            $serverId = DB::table('servers')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'team_id' => $site->team_id,
                'last_sample_at' => DB::table('site_metric_minutes')->where('site_id', $site->id)->max('minute'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('sites')->where('id', $site->id)->update(['server_id' => $serverId]);
            $this->copy('site_metric_minutes', 'site_id', $site->id, 'server_metric_minutes', 'server_id', $serverId, self::MINUTE_COLUMNS);
            $this->copy('site_metric_hours', 'site_id', $site->id, 'server_metric_hours', 'server_id', $serverId, self::HOUR_COLUMNS);
        }

        Schema::drop('site_metric_hours');
        Schema::drop('site_metric_minutes');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('site_metric_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $this->minuteColumns($table);

            $table->unique(['site_id', 'minute']);
        });

        Schema::create('site_metric_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $this->hourColumns($table);

            $table->unique(['site_id', 'hour']);
        });

        foreach (DB::table('sites')->whereNotNull('server_id')->get(['id', 'server_id']) as $site) {
            $this->copy('server_metric_minutes', 'server_id', $site->server_id, 'site_metric_minutes', 'site_id', $site->id, self::MINUTE_COLUMNS);
            $this->copy('server_metric_hours', 'server_id', $site->server_id, 'site_metric_hours', 'site_id', $site->id, self::HOUR_COLUMNS);
        }

        Schema::drop('server_metric_hours');
        Schema::drop('server_metric_minutes');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('server_id');
        });

        Schema::drop('servers');
    }

    private function minuteColumns(Blueprint $table): void
    {
        $table->timestamp('minute');
        $table->float('cpu')->nullable();
        $table->float('iowait')->nullable();
        $table->float('load1');
        $table->float('load5');
        $table->float('load15');
        $table->unsignedSmallInteger('cores');
        $table->unsignedBigInteger('memory_total');
        $table->unsignedBigInteger('memory_available');
        $table->unsignedBigInteger('swap_total');
        $table->unsignedBigInteger('swap_free');
        $table->unsignedBigInteger('disk_read')->nullable();
        $table->unsignedBigInteger('disk_write')->nullable();
        $table->unsignedBigInteger('network_in')->nullable();
        $table->unsignedBigInteger('network_out')->nullable();
        $table->unsignedInteger('uptime');
        $table->json('disks');
    }

    private function hourColumns(Blueprint $table): void
    {
        $table->timestamp('hour');
        $table->unsignedSmallInteger('samples');
        $table->float('cpu_average')->nullable();
        $table->float('cpu_peak')->nullable();
        $table->float('iowait_average')->nullable();
        $table->float('load_average');
        $table->float('load_peak');
        $table->unsignedSmallInteger('cores');
        $table->unsignedBigInteger('memory_total');
        $table->unsignedBigInteger('memory_used_average');
        $table->unsignedBigInteger('memory_used_peak');
        $table->unsignedBigInteger('swap_total');
        $table->unsignedBigInteger('swap_used_peak');
        $table->unsignedBigInteger('disk_read')->nullable();
        $table->unsignedBigInteger('disk_write')->nullable();
        $table->unsignedBigInteger('network_in')->nullable();
        $table->unsignedBigInteger('network_out')->nullable();
        $table->json('disks');
    }

    /**
     * Copy one owner's rows to another table, under another owner.
     *
     * @param  list<string>  $columns
     */
    private function copy(string $from, string $fromKey, int $fromId, string $to, string $toKey, int $toId, array $columns): void
    {
        $list = implode(', ', $columns);

        DB::insert("insert into {$to} ({$toKey}, {$list}) select ?, {$list} from {$from} where {$fromKey} = ?", [$toId, $fromId]);
    }
};
