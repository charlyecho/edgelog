<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ShipperScriptTest extends TestCase
{
    public function test_the_shipper_is_valid_posix_shell(): void
    {
        $result = Process::run(['sh', '-n', base_path('tools/edgelog-ship')]);

        $this->assertTrue($result->successful(), $result->errorOutput());
    }

    public function test_the_shipper_refuses_a_plain_http_url_to_another_host(): void
    {
        $result = Process::run(['sh', base_path('tools/edgelog-ship'), '--url', 'http://example.com/ingest/x', '--token-file', '/dev/null', '--log', '/dev/null']);

        $this->assertSame(2, $result->exitCode());
        $this->assertStringContainsString('must start with https://', $result->errorOutput());
    }
}
