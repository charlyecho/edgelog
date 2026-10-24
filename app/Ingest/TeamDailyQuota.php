<?php

namespace App\Ingest;

use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * The bytes a team's sites may ship in a UTC day, together.
 *
 * Bytes are counted as each batch is accepted (a refused or repeated batch
 * is not counted), with one conditional UPDATE so batches of the same team
 * sent at the same moment cannot pass the cap together.
 */
final readonly class TeamDailyQuota
{
    public function __construct(private Team $team, private int $capBytes) {}

    /**
     * The quota with the cap from the configuration.
     */
    public static function for(Team $team): self
    {
        return new self($team, (int) (config('ingest.team_daily_mb') * 1024 * 1024));
    }

    /**
     * Count the bytes against today when they fit under the cap.
     */
    public function reserve(int $bytes): bool
    {
        if ($bytes === 0) {
            return true;
        }

        $day = UtcDay::today();

        DB::table('ingest_team_days')->insertOrIgnore(['team_id' => $this->team->id, 'day' => $day, 'bytes' => 0]);

        return DB::table('ingest_team_days')
            ->where('team_id', $this->team->id)
            ->where('day', $day)
            ->where('bytes', '<=', $this->capBytes - $bytes)
            ->increment('bytes', $bytes) === 1;
    }

    /**
     * Give back bytes reserved for a batch that was not written after all.
     */
    public function release(int $bytes): void
    {
        DB::table('ingest_team_days')
            ->where('team_id', $this->team->id)
            ->where('day', UtcDay::today())
            ->update(['bytes' => DB::raw('CASE WHEN bytes > '.(int) $bytes.' THEN bytes - '.(int) $bytes.' ELSE 0 END')]);
    }

    /**
     * The bytes the team's sites have shipped today.
     */
    public function used(): int
    {
        return (int) DB::table('ingest_team_days')
            ->where('team_id', $this->team->id)
            ->where('day', UtcDay::today())
            ->value('bytes');
    }
}
