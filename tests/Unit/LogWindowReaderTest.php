<?php

namespace Tests\Unit;

use App\Analytics\DayWindow;
use App\Analytics\LogWindowReader;
use App\Enums\LogFormat;
use App\Ingest\IngestLog;
use App\Ingest\LogHit;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LogWindowReaderTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().'/edgelog-reader-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_lines_older_than_the_window_are_skipped_and_a_second_pass_reads_the_same(): void
    {
        File::put("{$this->folder}/".IngestLog::BASE, implode("\n", [
            $this->line('20/Oct/2026:23:59:59 +0000', '/old'),
            'garbage before the window',
            $this->line('20/Oct/2026:23:59:59 +0000', '/old-again'),
            '',
            $this->line('21/Oct/2026:00:00:00 +0000', '/first'),
            'garbage inside the window',
            $this->line('20/Oct/2026:23:00:00 +0000', '/late-shipper'),
            $this->line('21/Oct/2026:10:00:00 +0000', '/second'),
        ])."\n");

        $reader = new LogWindowReader($this->folder, LogFormat::NginxCombined->parser(), new DayWindow('2026-10-21', 'UTC'));

        foreach ([1, 2] as $pass) {
            $paths = array_map(fn (LogHit $hit) => $hit->path(), iterator_to_array($reader->hits(), false));

            $this->assertSame(['/first', '/second'], $paths, "pass {$pass}");
            $this->assertSame(3, $reader->lines, "pass {$pass}");
            $this->assertSame(1, $reader->unparsed, "pass {$pass}");
            $this->assertTrue($reader->reachesBack, "pass {$pass}");
        }
    }

    public function test_a_log_that_starts_inside_the_window_does_not_reach_back(): void
    {
        File::put("{$this->folder}/".IngestLog::BASE, $this->line('21/Oct/2026:08:00:00 +0000', '/only')."\n");

        $reader = new LogWindowReader($this->folder, LogFormat::NginxCombined->parser(), new DayWindow('2026-10-21', 'UTC'));

        foreach ([1, 2] as $pass) {
            $this->assertCount(1, iterator_to_array($reader->hits(), false), "pass {$pass}");
            $this->assertFalse($reader->reachesBack, "pass {$pass}");
        }
    }

    private function line(string $when, string $path): string
    {
        return "203.0.113.7 - - [{$when}] \"GET {$path} HTTP/1.1\" 200 12 \"-\" \"Mozilla/5.0\"";
    }
}
