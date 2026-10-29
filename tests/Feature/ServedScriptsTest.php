<?php

namespace Tests\Feature;

use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Enums\TrafficGroup;
use App\Ingest\IngestLog;
use App\Models\Site;
use App\Models\SiteDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ServedScriptsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-scripts-'.bin2hex(random_bytes(6)), 'ingest.keep' => 3]);
        $this->travelTo('2026-10-22 12:00:00');

        $this->site = Site::factory()->create(['url' => 'https://example.com', 'log_format' => 'auto', 'timezone' => 'UTC']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    private function line(string $time, string $address, string $path, string $referrer = '-', int $status = 200, string $agent = self::CHROME): string
    {
        $when = date('d/M/Y:H:i:s +0000', strtotime("2026-10-21 {$time} UTC"));

        return "{$address} - - [{$when}] \"GET {$path} HTTP/1.1\" {$status} 512 \"{$referrer}\" \"{$agent}\"";
    }

    /**
     * People reading a CodeIgniter app that routes through /index.php: each
     * of three addresses reads four routes from the site's own pages.
     *
     * @return list<string>
     */
    private function codeIgniterDay(int $status = 200): array
    {
        $lines = [$this->line('00:00:00', '198.51.100.9', '/')];

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $n => $address) {
            $previous = 'https://example.com/index.php/shop';
            $lines[] = $this->line("1{$n}:00:00", $address, '/index.php/shop', 'https://www.google.com/', $status);
            $lines[] = $this->line("1{$n}:00:01", $address, '/assets/app.css', $previous);

            foreach (range(1, 4) as $page) {
                $path = '/index.php/shop/item/'.($n * 4 + $page);
                $lines[] = $this->line("1{$n}:0{$page}:00", $address, $path, $previous, $status);
                $lines[] = $this->line("1{$n}:0{$page}:01", $address, '/assets/app.css', "https://example.com{$path}");
                $previous = "https://example.com{$path}";
            }
        }

        // A scanner, which stays one.
        $lines[] = $this->line('20:00:00', '203.0.113.7', '/wp-login.php', '-', 404, 'Mozilla/5.0');

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeLog(array $lines): void
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/".IngestLog::BASE, implode("\n", $lines)."\n");
    }

    private function collect(): SiteDay
    {
        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => '2026-10-21', '--sync' => true])->assertSuccessful();

        return $this->site->days()->whereDate('day', '2026-10-21')->with(['groups', 'counts'])->sole();
    }

    public function test_a_script_the_site_serves_its_pages_through_is_found_and_its_routes_are_pages(): void
    {
        $this->writeLog($this->codeIgniterDay());

        $day = $this->collect();

        $this->assertSame(['/index.php'], $this->site->fresh()->served_scripts);
        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));

        $groups = $day->groups->keyBy(fn ($group) => $group->group->value);
        $this->assertSame(15, $groups['people']->views, 'every route read is a page read by a person');
        $this->assertSame(1, $groups['bots']->probes, 'only the scanner probed');

        $probed = $day->counts->where('kind', CountKind::SecurityPath)->pluck('name')->all();
        $this->assertSame(['/wp-login.php'], $probed);
        $this->assertContains('/index.php/shop/item/7', $day->counts->where('kind', CountKind::PeoplePage)->pluck('name')->all());
    }

    public function test_a_site_that_answers_no_route_under_a_script_is_not_said_to_serve_through_it(): void
    {
        $this->writeLog($this->codeIgniterDay(status: 404));

        $day = $this->collect();

        $this->assertNull($this->site->fresh()->served_scripts);
        $this->assertSame(0, $day->groups->firstWhere('group', TrafficGroup::People)?->views ?? 0);
    }

    public function test_routes_read_from_elsewhere_than_the_sites_pages_are_no_proof(): void
    {
        $lines = array_map(fn (string $line) => str_replace('"https://example.com', '"https://elsewhere.example', $line), $this->codeIgniterDay());
        $this->writeLog($lines);

        $this->collect();

        $this->assertNull($this->site->fresh()->served_scripts);
    }

    public function test_the_settings_say_what_was_found(): void
    {
        $this->site->forceFill(['served_scripts' => ['/index.php']])->save();
        $owner = User::factory()->create();
        $this->site->team->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->get(route('teams.sites.edit', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('the site serves its pages through /index.php');
    }
}
