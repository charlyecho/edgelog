<?php

namespace Tests\Unit;

use App\Ingest\BatchId;
use App\Ingest\IngestLog;
use App\Ingest\IngestLogFull;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IngestLogTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().'/edgelog-ingest-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_batches_are_appended_in_order(): void
    {
        $log = new IngestLog($this->folder);

        $this->assertTrue($log->append(new BatchId('a', 1), "one\n"));
        $this->assertTrue($log->append(new BatchId('a', 2), "two\n"));

        $this->assertSame("one\ntwo\n", file_get_contents($log->path()));
    }

    public function test_a_batch_sent_twice_is_kept_once(): void
    {
        $log = new IngestLog($this->folder);
        $log->append(new BatchId('a', 1), "one\n");

        $this->assertTrue($log->hasSeen(new BatchId('a', 1)));
        $this->assertFalse($log->append(new BatchId('a', 1), "one\n"));
        $this->assertFalse($log->hasSeen(new BatchId('b', 1)));

        $this->assertSame("one\n", file_get_contents($log->path()));
    }

    public function test_a_new_utc_day_rotates_the_file(): void
    {
        $this->travelTo('2026-10-21 23:59:00');
        $log = new IngestLog($this->folder, keep: 3);
        $log->append(new BatchId('a', 1), "day one\n");

        $this->travelTo('2026-10-22 00:01:00');
        $log->append(new BatchId('a', 2), "day two\n");

        $this->travelTo('2026-10-23 00:01:00');
        $log->append(new BatchId('a', 3), "day three\n");

        $this->assertSame("day three\n", file_get_contents($log->path()));
        $this->assertSame("day two\n", file_get_contents($log->path().'.1'));
        $this->assertSame("day one\n", gzdecode(file_get_contents($log->path().'.2.gz')));
    }

    public function test_the_oldest_rotation_is_dropped(): void
    {
        $log = new IngestLog($this->folder, keep: 2);

        foreach (['2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'] as $seq => $day) {
            $this->travelTo("{$day} 12:00:00");
            $log->append(new BatchId('a', $seq + 1), "{$day}\n");
        }

        $this->assertSame("2026-10-22\n", file_get_contents($log->path().'.1'));
        $this->assertSame("2026-10-21\n", gzdecode(file_get_contents($log->path().'.2.gz')));
        $this->assertFileDoesNotExist($log->path().'.3.gz');
    }

    public function test_a_day_past_its_ceiling_refuses_the_batch(): void
    {
        $log = new IngestLog($this->folder, maxBytes: 10);
        $log->append(new BatchId('a', 1), "12345678\n");

        $this->expectException(IngestLogFull::class);

        $log->append(new BatchId('a', 2), "more\n");
    }

    public function test_a_partial_append_left_by_a_crash_is_cut_off(): void
    {
        $log = new IngestLog($this->folder);
        $log->append(new BatchId('a', 1), "one\n");
        $this->simulateCrash($log, start: 4, length: 4, written: 'tw');

        $this->assertTrue($log->append(new BatchId('a', 2), "two\n"));

        $this->assertSame("one\ntwo\n", file_get_contents($log->path()));
    }

    public function test_a_complete_append_left_by_a_crash_is_kept_once(): void
    {
        $log = new IngestLog($this->folder);
        $log->append(new BatchId('a', 1), "one\n");
        $this->simulateCrash($log, start: 4, length: 4, written: "two\n");

        $this->assertFalse($log->append(new BatchId('a', 2), "two\n"));

        $this->assertSame("one\ntwo\n", file_get_contents($log->path()));
    }

    /**
     * Leave the folder as a crash during the append of batch a:2 would.
     */
    private function simulateCrash(IngestLog $log, int $start, int $length, string $written): void
    {
        $statePath = $this->folder.'/.ingest-state.json';
        $state = json_decode(file_get_contents($statePath), true);
        $state['pending'] = ['shipper' => 'a', 'seq' => 2, 'start' => $start, 'length' => $length];
        file_put_contents($statePath, json_encode($state));
        file_put_contents($log->path(), $written, FILE_APPEND);
    }
}
