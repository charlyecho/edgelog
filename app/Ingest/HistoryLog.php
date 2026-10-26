<?php

namespace App\Ingest;

use App\Models\Site;
use Closure;
use RuntimeException;

/**
 * Where a site's past log lines are kept, sent from the web server's old
 * files by `edgelog-ship --backfill`, one file per UTC day of the lines' own
 * times, kept for good:
 *
 *     <root>/<site uuid>/history/2026-03-14.log.gz
 *
 * Only lines older than the first one shipped live are kept, so a line is
 * never in both. Each past file is sent by parts, start to end, under its
 * id; the state keeps how far each file went, so a part sent again, or a
 * file sent twice, is not written twice. The append is journaled: the state
 * names the part and the size of every day file before the bytes are
 * written; a crash in between is undone at the next write, and the shipper
 * sends the same part again.
 *
 * The days a part touched are kept in the state until edgelog:count-history
 * takes them, once no part came for a while, and counts them again.
 *
 * One writer at a time per site, under an flock on the folder's lock file.
 */
final readonly class HistoryLog
{
    public const string FOLDER = 'history';

    private const string STATE = '.history-state.json';

    private const string LOCK = '.history.lock';

    public function __construct(private string $folder) {}

    /**
     * The past lines of the given site.
     */
    public static function for(Site $site): self
    {
        return self::in(IngestLog::for($site)->folder());
    }

    /**
     * The past lines kept in the given site folder.
     */
    public static function in(string $siteFolder): self
    {
        return new self(rtrim($siteFolder, '/').'/'.self::FOLDER);
    }

    /**
     * The path of the file of the given UTC day.
     */
    public function path(string $day): string
    {
        return "{$this->folder}/{$day}.log.gz";
    }

    /**
     * The files of the UTC days from one to the other, both included, oldest first.
     *
     * @return list<string>
     */
    public function files(string $fromDay, string $toDay): array
    {
        return array_values(array_filter(
            array_map(fn (string $day) => $this->path($day), $this->days()),
            fn (string $path) => basename($path, '.log.gz') >= $fromDay && basename($path, '.log.gz') <= $toDay,
        ));
    }

    /**
     * Determine if a day before the given one holds past lines.
     */
    public function hasDayBefore(string $day): bool
    {
        $days = $this->days();

        return $days !== [] && $days[0] < $day;
    }

    /**
     * How far the past file with the given id was kept, in bytes of its lines.
     */
    public function offset(string $source): int
    {
        return $this->readState()['sources'][$source]['offset'] ?? 0;
    }

    /**
     * Append a part of a past file, its lines already put by UTC day.
     * Returns false, and writes nothing, when the part does not start where
     * the file was kept up to.
     *
     * @param  array<string, string>  $linesByDay  each day's lines, each ending with a new line
     */
    public function append(HistoryRange $range, array $linesByDay, int $lines): bool
    {
        return $this->locked(function () use ($range, $linesByDay, $lines) {
            $state = $this->readState();
            $this->recover($state);

            if (($state['sources'][$range->source]['offset'] ?? 0) !== $range->start) {
                return false;
            }

            $linesByDay = array_filter($linesByDay, fn (string $data) => $data !== '');
            ksort($linesByDay);

            if ($linesByDay !== []) {
                $sizes = [];

                foreach (array_keys($linesByDay) as $day) {
                    $sizes[$day] = $this->size($this->path($day));
                }

                $state['pending'] = ['source' => $range->source, 'sizes' => $sizes];
                $this->writeState($state);

                foreach ($linesByDay as $day => $data) {
                    $this->write($this->path($day), (string) gzencode($data, 6));
                }

                $state['touched'] = array_values(array_unique([...$state['touched'], ...array_map('strval', array_keys($linesByDay))]));
                sort($state['touched']);
            }

            $state['pending'] = null;
            $state['sources'][$range->source] = [
                'offset' => $range->end,
                'lines' => ($state['sources'][$range->source]['lines'] ?? 0) + $lines,
                'at' => now('UTC')->toIso8601ZuluString(),
            ];
            $state['received_at'] = now('UTC')->toIso8601ZuluString();
            $this->writeState($state);

            return true;
        });
    }

    /**
     * The UTC days past lines were added to, once no part came for the given
     * seconds; they are forgotten as they are given, to be counted once.
     *
     * @return list<string>
     */
    public function takeSettledDays(int $settleSeconds): array
    {
        if (! is_file("{$this->folder}/".self::STATE)) {
            return [];
        }

        return $this->locked(function () use ($settleSeconds) {
            $state = $this->readState();

            if ($state['touched'] === [] || $state['received_at'] === null || now()->subSeconds($settleSeconds)->lessThan($state['received_at'])) {
                return [];
            }

            $days = $state['touched'];
            $state['touched'] = [];
            $this->writeState($state);

            return $days;
        });
    }

    /**
     * What the past logs hold, for the site's settings.
     *
     * @return array{files: int, lines: int, first: ?string, last: ?string, received_at: ?string, counting: bool}
     */
    public function summary(): array
    {
        $state = $this->readState();
        $days = $this->days();

        return [
            'files' => count($state['sources']),
            'lines' => array_sum(array_column($state['sources'], 'lines')),
            'first' => $days[0] ?? null,
            'last' => $days === [] ? null : $days[count($days) - 1],
            'received_at' => $state['received_at'],
            'counting' => $state['touched'] !== [],
        ];
    }

    /**
     * The UTC days that have a file, oldest first.
     *
     * @return list<string>
     */
    private function days(): array
    {
        $days = [];

        foreach (glob("{$this->folder}/*.log.gz") ?: [] as $path) {
            $day = basename($path, '.log.gz');

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
                $days[] = $day;
            }
        }

        sort($days);

        return $days;
    }

    /**
     * @param  array{pending: ?array{source: string, sizes: array<string, int>}}  $state
     */
    private function recover(array &$state): void
    {
        if ($state['pending'] === null) {
            return;
        }

        foreach ($state['pending']['sizes'] as $day => $size) {
            $this->truncate($this->path((string) $day), (int) $size);
        }

        $state['pending'] = null;
        $this->writeState($state);
    }

    /**
     * @return array{sources: array<string, array{offset: int, lines: int, at: string}>, touched: list<string>, received_at: ?string, pending: ?array{source: string, sizes: array<string, int>}}
     */
    private function readState(): array
    {
        $path = "{$this->folder}/".self::STATE;
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return [
            'sources' => is_array($state['sources'] ?? null) ? $state['sources'] : [],
            'touched' => is_array($state['touched'] ?? null) ? $state['touched'] : [],
            'received_at' => $state['received_at'] ?? null,
            'pending' => is_array($state['pending'] ?? null) ? $state['pending'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(array $state): void
    {
        $path = "{$this->folder}/".self::STATE;
        $temporary = "{$path}.tmp";

        $handle = fopen($temporary, 'wb');
        fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        fflush($handle);
        fsync($handle);
        fclose($handle);

        rename($temporary, $path);
    }

    /**
     * Append one gzip member: a file of several members reads as one.
     */
    private function write(string $path, string $member): void
    {
        $handle = fopen($path, 'ab');

        if ($handle === false || fwrite($handle, $member) !== strlen($member)) {
            throw new RuntimeException('The past log lines could not be written.');
        }

        fflush($handle);
        fsync($handle);
        fclose($handle);
        chmod($path, 0640);
    }

    private function truncate(string $path, int $size): void
    {
        if ($size === 0) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        if ($this->size($path) > $size) {
            $handle = fopen($path, 'r+b');
            ftruncate($handle, $size);
            fflush($handle);
            fsync($handle);
            fclose($handle);
        }
    }

    private function size(string $path): int
    {
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : 0;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function locked(Closure $callback): mixed
    {
        if (! is_dir($this->folder)) {
            mkdir($this->folder, 0750, true);
        }

        $lock = fopen("{$this->folder}/".self::LOCK, 'c');
        flock($lock, LOCK_EX);

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
