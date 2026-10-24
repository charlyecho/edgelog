<?php

namespace App\Ingest;

use App\Models\Site;
use Closure;
use RuntimeException;

/**
 * Where one site's shipped log lines are kept, rotated by UTC day:
 *
 *     <root>/<site uuid>/access.log        today's lines
 *                        access.log.1      the day before, plain
 *                        access.log.2.gz   ... access.log.<keep>.gz
 *
 * Each batch is kept once: the last seq of every shipper is kept in the
 * folder's state file, and a seq at or below it is not written again.
 * The append is journaled: the state names the batch, where it starts and
 * how long it is before the bytes are written, and records its seq after
 * they are on disk. A crash in between is repaired at the next write: a
 * complete append is kept and its seq recorded, a partial one is cut off.
 *
 * One writer at a time per site, under an flock on the folder's lock file.
 */
final readonly class IngestLog
{
    public const string BASE = 'access.log';

    private const string STATE = '.ingest-state.json';

    private const string LOCK = '.ingest.lock';

    private const int MAX_SHIPPERS = 200;

    public function __construct(
        private string $folder,
        private int $keep = 14,
        private int $maxBytes = 1024 * 1024 * 1024,
    ) {}

    /**
     * The log of the given site, with the limits from the configuration.
     */
    public static function for(Site $site): self
    {
        return new self(
            rtrim(config('ingest.root'), '/').'/'.$site->uuid,
            (int) config('ingest.keep'),
            (int) (config('ingest.max_mb') * 1024 * 1024),
        );
    }

    /**
     * The site's folder, where every one of its files is kept.
     */
    public function folder(): string
    {
        return $this->folder;
    }

    /**
     * The path of today's file.
     */
    public function path(): string
    {
        return "{$this->folder}/".self::BASE;
    }

    /**
     * Determine if the batch was kept before.
     */
    public function hasSeen(BatchId $batch): bool
    {
        return $this->lastSeq($this->readState(), $batch->shipper) >= $batch->seq;
    }

    /**
     * Append the batch's lines. Returns false when the batch was kept before
     * and nothing was written.
     *
     * @throws IngestLogFull when today's file would pass its ceiling
     */
    public function append(BatchId $batch, string $data): bool
    {
        return $this->locked(function () use ($batch, $data) {
            $state = $this->readState();
            $this->recover($state);

            if ($this->lastSeq($state, $batch->shipper) >= $batch->seq) {
                return false;
            }

            $today = UtcDay::today();
            $this->rotateIfNewDay($state, $today);

            $size = $this->size();

            if ($data !== '' && $size + strlen($data) > $this->maxBytes) {
                throw new IngestLogFull;
            }

            if ($data !== '') {
                $state['pending'] = ['shipper' => $batch->shipper, 'seq' => $batch->seq, 'start' => $size, 'length' => strlen($data)];
                $this->writeState($state);
                $this->write($data);
                $state['day'] = $today;
            }

            $this->markKept($state, $batch->shipper, $batch->seq);
            $this->writeState($state);

            return true;
        });
    }

    /**
     * Move today's file aside: access.log -> .1 -> .2.gz ... -> .<keep>.gz,
     * the oldest dropped, every file keeping its modification time.
     */
    public function rotate(): void
    {
        $base = $this->path();
        $oldest = "{$base}.{$this->keep}.gz";

        if ($this->keep >= 2 && is_file($oldest)) {
            unlink($oldest);
        }

        for ($n = $this->keep - 1; $n > 1; $n--) {
            if (is_file("{$base}.{$n}.gz")) {
                rename("{$base}.{$n}.gz", "{$base}.".($n + 1).'.gz');
            }
        }

        if (is_file("{$base}.1")) {
            if ($this->keep >= 2) {
                $this->gzip("{$base}.1", "{$base}.2.gz");
            }

            unlink("{$base}.1");
        }

        if (is_file($base)) {
            rename($base, "{$base}.1");
        }
    }

    /**
     * @param  array{day: ?string, shippers: array<string, array{seq: int, at: string}>, pending: ?array{shipper: string, seq: int, start: int, length: int}}  $state
     */
    private function recover(array &$state): void
    {
        $pending = $state['pending'];

        if ($pending === null) {
            return;
        }

        if ($this->size() >= $pending['start'] + $pending['length']) {
            $this->markKept($state, $pending['shipper'], $pending['seq']);
        } else {
            $this->truncate($pending['start']);
            $state['pending'] = null;
        }

        $this->writeState($state);
    }

    /**
     * @param  array{day: ?string}  $state
     */
    private function rotateIfNewDay(array &$state, string $today): void
    {
        $day = $state['day'] ?? (is_file($this->path()) ? gmdate('Y-m-d', (int) filemtime($this->path())) : null);

        if ($day !== null && $day < $today && $this->size() > 0) {
            $this->rotate();
        }

        $state['day'] = $today;
    }

    /**
     * @param  array{shippers: array<string, array{seq: int, at: string}>, pending: mixed}  $state
     */
    private function markKept(array &$state, string $shipper, int $seq): void
    {
        $state['pending'] = null;
        $state['shippers'][$shipper] = ['seq' => $seq, 'at' => now('UTC')->toIso8601ZuluString()];

        if (count($state['shippers']) > self::MAX_SHIPPERS) {
            uasort($state['shippers'], fn (array $a, array $b) => strcmp($b['at'], $a['at']));
            $state['shippers'] = array_slice($state['shippers'], 0, self::MAX_SHIPPERS, true);
        }
    }

    /**
     * @param  array{shippers: array<string, array{seq: int}>}  $state
     */
    private function lastSeq(array $state, string $shipper): int
    {
        return $state['shippers'][$shipper]['seq'] ?? -1;
    }

    /**
     * @return array{day: ?string, shippers: array<string, array{seq: int, at: string}>, pending: ?array{shipper: string, seq: int, start: int, length: int}}
     */
    private function readState(): array
    {
        $path = "{$this->folder}/".self::STATE;
        $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return [
            'day' => $state['day'] ?? null,
            'shippers' => is_array($state['shippers'] ?? null) ? $state['shippers'] : [],
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

    private function write(string $data): void
    {
        $handle = fopen($this->path(), 'ab');

        if ($handle === false || fwrite($handle, $data) !== strlen($data)) {
            throw new RuntimeException('The log lines could not be written.');
        }

        fflush($handle);
        fsync($handle);
        fclose($handle);
        chmod($this->path(), 0640);
    }

    private function truncate(int $size): void
    {
        if ($this->size() > $size) {
            $handle = fopen($this->path(), 'r+b');
            ftruncate($handle, $size);
            fflush($handle);
            fsync($handle);
            fclose($handle);
        }
    }

    private function size(): int
    {
        clearstatcache(true, $this->path());

        return is_file($this->path()) ? (int) filesize($this->path()) : 0;
    }

    private function gzip(string $source, string $destination): void
    {
        $temporary = "{$destination}.tmp";
        $in = fopen($source, 'rb');
        $out = gzopen($temporary, 'wb6');

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 1024));
        }

        fclose($in);
        gzclose($out);
        chmod($temporary, 0640);
        touch($temporary, (int) filemtime($source));
        rename($temporary, $destination);
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
