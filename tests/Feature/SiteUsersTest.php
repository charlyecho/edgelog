<?php

namespace Tests\Feature;

use App\Enums\ConversionAction;
use App\Ingest\IngestLog;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SiteUsersTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private Site $site;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-users-'.bin2hex(random_bytes(6))]);
        $this->travelTo('2026-10-23 08:00:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com', 'log_format' => 'auto']);
        $this->viewer = User::factory()->create();
        $this->site->team->members()->attach($this->viewer, ['role' => 'viewer']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    private function line(string $date, string $time, string $address, string $path, string $uid = '-', string $referrer = '-'): string
    {
        $when = date('d/M/Y:H:i:s +0000', strtotime("{$date} {$time} UTC"));

        return "{$address} - - [{$when}] \"GET {$path} HTTP/1.1\" 200 512 \"{$referrer}\" \"".self::CHROME."\" uid={$uid}";
    }

    private function collect(string $date, array $lines): void
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/access.log", implode("\n", [
            $this->line(date('Y-m-d', strtotime("{$date} -1 day")), '23:59:00', '198.51.100.9', '/'),
            ...$lines,
            $this->line(date('Y-m-d', strtotime("{$date} +1 day")), '00:00:01', '198.51.100.9', '/'),
        ])."\n");

        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => $date, '--sync' => true])->assertSuccessful();
    }

    /**
     * A user who came from a search and reads two pages, on the 21st and again on the 22nd.
     */
    private function twoDays(): void
    {
        foreach (['2026-10-21', '2026-10-22'] as $date) {
            $this->collect($date, [
                $this->line($date, '10:00:00', '198.51.100.1', '/pricing/', 'u_42', 'https://www.google.com/'),
                $this->line($date, '10:00:01', '198.51.100.1', '/app.css', 'u_42', 'https://example.com/pricing/'),
                $this->line($date, '10:02:00', '198.51.100.1', '/account/', 'u_42', 'https://example.com/pricing/'),
                $this->line($date, '12:00:00', '198.51.100.2', '/', $date === '2026-10-22' ? 'u_7' : '-'),
                $this->line($date, '12:00:01', '198.51.100.2', '/app.css', $date === '2026-10-22' ? 'u_7' : '-', 'https://example.com/'),
            ]);
        }

        $this->site->conversions()->create(['tool' => 'stripe', 'external_ref' => 'pi_1', 'action' => ConversionAction::Paid, 'amount' => 4900, 'currency' => 'eur', 'livemode' => true, 'occurred_at' => '2026-10-22 11:00:00', 'uid' => 'u_42']);
    }

    public function test_the_users_of_the_period_are_listed_with_their_figures(): void
    {
        $this->twoDays();

        $this->actingAs($this->viewer)->get(route('teams.sites.sales.users', [$this->site->team, $this->site, 'days' => '7']))
            ->assertOk()
            ->assertSeeInOrder(['u_42', '21 Oct 2026', 'Search engine', '/pricing/', '2', '4', '22 Oct', '€49.00', 'u_7'])
            ->assertSee('Users seen');

        $this->actingAs($this->viewer)->get(route('teams.sites.sales.users', [$this->site->team, $this->site, 'days' => '7', 'q' => 'u_7']))
            ->assertOk()->assertSee('u_7')->assertDontSee('u_42');
    }

    public function test_a_user_has_a_page_of_their_own(): void
    {
        $this->twoDays();

        $this->actingAs($this->viewer)->get(route('teams.sites.sales.users.show', [$this->site->team, $this->site, 'u_42']))
            ->assertOk()
            ->assertSee('Their first visit')
            ->assertSee('Thu 22 Oct 2026')
            ->assertSee('€49.00');

        $this->actingAs($this->viewer)->get(route('teams.sites.sales.users.show', [$this->site->team, $this->site, 'nobody']))->assertNotFound();
    }

    public function test_someone_outside_the_team_cannot_see_the_users(): void
    {
        $this->actingAs(User::factory()->create())->get(route('teams.sites.sales.users', [$this->site->team, $this->site]))->assertForbidden();
    }
}
