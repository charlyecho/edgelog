<?php

namespace Tests\Feature;

use App\Analytics\NotPages;
use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Ingest\IngestLog;
use App\Jobs\AuditSiteDay;
use App\Jobs\CollectSiteDay;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NotPagesTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private Site $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-not-pages-'.bin2hex(random_bytes(6))]);
        $this->travelTo('2026-10-23 08:00:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com', 'log_format' => 'auto']);
        $this->owner = User::factory()->create();
        $this->site->team->members()->attach($this->owner, ['role' => 'owner']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    private function line(string $time, string $path, string $referrer = '-'): string
    {
        $when = date('d/M/Y:H:i:s +0000', strtotime("{$time} UTC"));

        return "198.51.100.1 - - [{$when}] \"GET {$path} HTTP/1.1\" 200 512 \"{$referrer}\" \"".self::CHROME.'"';
    }

    public function test_a_pattern_matches_whole_paths_with_stars_for_any_characters(): void
    {
        $notPages = new NotPages(['/user/home_ajax/*', '*/poll']);

        $this->assertTrue($notPages->matches('/user/home_ajax/2026-10-24'));
        $this->assertTrue($notPages->matches('/a/b/poll'));
        $this->assertFalse($notPages->matches('/user/home'));
        $this->assertFalse($notPages->matches('/user/home_ajax'));
        $this->assertFalse($notPages->matches('/poll/results'));
        $this->assertFalse((new NotPages)->matches('/anything'));
    }

    public function test_patterns_are_read_one_per_line_and_checked(): void
    {
        $this->assertSame(['/a/*', '/b'], NotPages::parse(" /a/* \r\n\n/b\n/a/*\n"));
        $this->assertTrue(NotPages::isValid('/user/home_ajax/*'));
        $this->assertTrue(NotPages::isValid('*/ajax/*'));
        $this->assertFalse(NotPages::isValid('/'));
        $this->assertFalse(NotPages::isValid('/*'));
        $this->assertFalse(NotPages::isValid('user/home'));
        $this->assertFalse(NotPages::isValid('/user home'));
    }

    public function test_a_path_that_is_not_a_page_stays_in_the_visit_but_out_of_the_pages(): void
    {
        $this->site->update(['not_pages' => ['/user/home_ajax/*']]);
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/access.log", implode("\n", [
            $this->line('2026-10-20 23:59:00', '/'),
            $this->line('2026-10-21 10:00:00', '/user/home'),
            $this->line('2026-10-21 10:00:00', '/app.css', 'https://example.com/user/home'),
            $this->line('2026-10-21 10:00:01', '/user/home_ajax/2026-10-21', 'https://example.com/user/home'),
            $this->line('2026-10-21 10:04:00', '/user/home_ajax/2026-10-22', 'https://example.com/user/home'),
            $this->line('2026-10-22 00:00:01', '/'),
        ])."\n");

        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => '2026-10-21', '--sync' => true])->assertSuccessful();

        $day = $this->site->days()->whereDate('day', '2026-10-21')->with('counts')->first();
        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $this->assertSame(['/user/home' => 1], $day->counts->where('kind', CountKind::PeoplePage)->pluck('count', 'name')->all());
        $this->assertSame(['/user/home' => 1], $day->counts->where('kind', CountKind::VisitExit)->pluck('count', 'name')->all());

        $visit = $day->visits()->first();
        $this->assertSame(['/user/home'], $visit->trail);
        $this->assertSame(240, $visit->seconds);
    }

    public function test_the_owner_saves_the_paths_and_counts_the_last_days_again(): void
    {
        Bus::fake();

        $this->actingAs($this->owner)
            ->from(route('teams.sites.edit', [$this->site->team, $this->site]))
            ->put(route('teams.sites.paths.update', [$this->site->team, $this->site]), [
                'not_pages' => "/user/home_ajax/*\n\n*/poll",
                'recount' => '1',
            ])
            ->assertRedirect(route('teams.sites.edit', [$this->site->team, $this->site]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['/user/home_ajax/*', '*/poll'], $this->site->fresh()->not_pages);
        Bus::assertChained([
            ...array_merge(...array_map(fn () => [CollectSiteDay::class, AuditSiteDay::class], range(1, 7))),
        ]);

        $this->actingAs($this->owner)
            ->get(route('teams.sites.edit', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('Paths that are not pages')->assertSee('Paths the site really has')
            ->assertSee('/user/home_ajax/*');
    }

    public function test_a_path_that_would_leave_no_page_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->put(route('teams.sites.paths.update', [$this->site->team, $this->site]), ['not_pages' => '/*'])
            ->assertSessionHasErrors('not_pages');

        $this->assertNull($this->site->fresh()->not_pages);
    }

    public function test_a_viewer_cannot_change_the_paths(): void
    {
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->put(route('teams.sites.paths.update', [$this->site->team, $this->site]), ['not_pages' => '/a/*'])
            ->assertForbidden();
    }
}
