<?php

namespace Tests\Feature;

use App\Analytics\Live\LiveCollector;
use App\Analytics\SitePeriod;
use App\Enums\DayStatus;
use App\Enums\IntegrationTool;
use App\Enums\LogFormat;
use App\Ingest\IngestLog;
use App\Integrations\Stripe\StripeEvents;
use App\Integrations\Stripe\StripeSignature;
use App\Jobs\AuditSiteDay;
use App\Jobs\CollectSiteDay;
use App\Models\Conversion;
use App\Models\Site;
use App\Models\SiteDayUser;
use App\Models\SiteUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UserTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-users-'.bin2hex(random_bytes(6))]);
        $this->travelTo('2026-10-23 12:00:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    private function line(string $time, string $address, string $path, string $uid = '-', string $agent = self::CHROME, string $referrer = '-'): string
    {
        $when = date('d/M/Y:H:i:s +0000', strtotime("{$time} UTC"));

        return "{$address} - - [{$when}] \"GET {$path} HTTP/1.1\" 200 512 \"{$referrer}\" \"{$agent}\" uid={$uid}";
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeLog(array $lines): void
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/access.log", implode("\n", [
            $this->line('2026-10-19 10:00:00', '198.51.100.99', '/', '-', 'curl/8'),
            ...$lines,
        ])."\n");
    }

    private function collect(string $day): void
    {
        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => $day, '--sync' => true])->assertSuccessful();
    }

    public function test_the_parsers_read_the_uid(): void
    {
        $combined = LogFormat::NginxCombined->parser()->parse($this->line('2026-10-21 10:00:00', '198.51.100.1', '/', 'u_42').' "www.example.com"');
        $extended = LogFormat::NginxExtended->parser()->parse('2026-10-21T10:00:00+00:00 198.51.100.1 example.com "GET / HTTP/1.1" 200 1 "-" "x" 0.01 cc=FR uid=abc123');
        $none = LogFormat::NginxCombined->parser()->parse($this->line('2026-10-21 10:00:00', '198.51.100.1', '/'));
        $odd = LogFormat::NginxCombined->parser()->parse($this->line('2026-10-21 10:00:00', '198.51.100.1', '/', 'a@b.com'));

        $this->assertSame('u_42', $combined->userId);
        $this->assertSame('www.example.com', $combined->host);
        $this->assertSame('abc123', $extended->userId);
        $this->assertSame('', $none->userId);
        $this->assertSame('', $odd->userId, 'an email is never a uid');
    }

    public function test_a_day_counts_its_signed_in_users_and_the_audit_agrees(): void
    {
        $this->writeLog([
            // Signs in on their second page: the whole visit is signed in.
            $this->line('2026-10-21 10:00:00', '198.51.100.1', '/', '-', self::CHROME, 'https://www.google.com/'),
            $this->line('2026-10-21 10:00:01', '198.51.100.1', '/app.css', '-', self::CHROME, 'https://example.com/'),
            $this->line('2026-10-21 10:01:00', '198.51.100.1', '/account/', 'u_1'),
            // The same user, later, from a phone.
            $this->line('2026-10-21 18:00:00', '203.0.113.5', '/account/', 'u_1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'),
            $this->line('2026-10-21 18:00:01', '203.0.113.5', '/app.js', 'u_1', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'),
            // Another user.
            $this->line('2026-10-21 11:00:00', '198.51.100.2', '/docs/', 'u_2'),
            $this->line('2026-10-21 11:00:01', '198.51.100.2', '/app.css', 'u_2'),
            // A visitor who never signs in.
            $this->line('2026-10-21 12:00:00', '198.51.100.3', '/pricing/'),
            $this->line('2026-10-21 12:00:01', '198.51.100.3', '/app.css'),
            // A bot carrying a uid is not a user.
            $this->line('2026-10-21 13:00:00', '198.51.100.4', '/', 'u_bot', 'curl/8'),
        ]);

        $this->collect('2026-10-21');

        $day = $this->site->days()->sole();
        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $this->assertSame(2, $day->signed_in_users);
        $this->assertSame(3, $day->signed_in_readers);
        $this->assertSame(['u_1' => 2, 'u_2' => 1], SiteDayUser::query()->orderBy('uid')->pluck('views', 'uid')->all());
        $this->assertSame('Search engine', SiteUser::firstWhere('uid', 'u_1')->first_source);
        $this->assertSame('/', SiteUser::firstWhere('uid', 'u_1')->first_landing);
    }

    public function test_the_recount_withholds_a_day_whose_users_were_counted_wrong(): void
    {
        $this->writeLog([
            $this->line('2026-10-21 11:00:00', '198.51.100.2', '/docs/', 'u_2'),
            $this->line('2026-10-21 11:00:01', '198.51.100.2', '/app.css', 'u_2'),
        ]);
        dispatch_sync(new CollectSiteDay($this->site, '2026-10-21'));
        $this->site->days()->sole()->update(['signed_in_users' => 5]);

        dispatch_sync(new AuditSiteDay($this->site, '2026-10-21'));

        $this->assertSame(DayStatus::Withheld, $this->site->days()->sole()->status);
    }

    public function test_users_over_a_period_are_distinct_never_summed(): void
    {
        $this->writeLog([
            $this->line('2026-10-21 11:00:00', '198.51.100.2', '/docs/', 'u_2'),
            $this->line('2026-10-21 11:00:01', '198.51.100.2', '/app.css', 'u_2'),
            $this->line('2026-10-22 11:00:00', '198.51.100.2', '/docs/', 'u_2'),
            $this->line('2026-10-22 11:00:01', '198.51.100.2', '/app.css', 'u_2'),
            $this->line('2026-10-22 12:00:00', '198.51.100.3', '/docs/', 'u_3'),
            $this->line('2026-10-22 12:00:01', '198.51.100.3', '/app.css', 'u_3'),
        ]);
        $this->collect('2026-10-21');
        $this->collect('2026-10-22');

        $this->assertSame(2, SitePeriod::lastDays($this->site, 7)->signedInUsers());
    }

    public function test_a_user_keeps_their_earliest_first_visit_whatever_order_days_are_counted_in(): void
    {
        $this->writeLog([
            $this->line('2026-10-21 11:00:00', '198.51.100.2', '/first/', 'u_2', self::CHROME, 'https://t.co/x'),
            $this->line('2026-10-21 11:00:01', '198.51.100.2', '/app.css', 'u_2'),
            $this->line('2026-10-22 11:00:00', '198.51.100.2', '/second/', 'u_2'),
            $this->line('2026-10-22 11:00:01', '198.51.100.2', '/app.css', 'u_2'),
        ]);
        $this->collect('2026-10-22');
        $this->collect('2026-10-21');

        $user = SiteUser::sole();
        $this->assertSame('/first/', $user->first_landing);
        $this->assertSame('Social media', $user->first_source);
        $this->assertSame('2026-10-22', $user->last_seen_day->toDateString());
    }

    public function test_ids_older_than_the_kept_days_are_forgotten(): void
    {
        config(['analytics.users_kept_days' => 1]);
        $this->writeLog([
            $this->line('2026-10-20 11:00:00', '198.51.100.2', '/docs/', 'u_2'),
            $this->line('2026-10-20 11:00:01', '198.51.100.2', '/app.css', 'u_2'),
        ]);
        $this->collect('2026-10-20');

        $this->artisan('edgelog:run-daily')->assertSuccessful();

        $this->assertSame(0, SiteDayUser::count());
        $this->assertSame(0, SiteUser::count());
        $this->assertSame(1, $this->site->days()->whereDate('day', '2026-10-20')->sole()->signed_in_users, 'the day keeps its count');
    }

    public function test_real_time_counts_the_users_signed_in_today(): void
    {
        $this->writeLog([
            $this->line('2026-10-23 11:50:00', '198.51.100.2', '/docs/', 'u_2'),
            $this->line('2026-10-23 11:50:01', '198.51.100.2', '/app.css', 'u_2'),
            $this->line('2026-10-23 09:00:00', '198.51.100.3', '/docs/', 'u_3'),
            $this->line('2026-10-23 09:00:01', '198.51.100.3', '/app.css', 'u_3'),
        ]);

        $live = app(LiveCollector::class)->collect($this->site);

        $this->assertSame(2, $live->signedInToday);
        $this->assertSame(1, $live->signedInRecent);
        $this->assertTrue($live->newest[0]['signed_in']);
    }

    public function test_a_payment_keeps_the_uid_the_app_passed_to_stripe(): void
    {
        $this->site->integrations()->create(['tool' => IntegrationTool::Stripe, 'secret' => 'whsec_x']);
        $body = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed', 'created' => time(), 'livemode' => true,
            'data' => ['object' => ['id' => 'cs_1', 'mode' => 'payment', 'payment_status' => 'paid', 'amount_total' => 900, 'currency' => 'eur', 'client_reference_id' => 'u_2']]]);

        $this->call('POST', route('hooks.stripe', $this->site), [], [], [], ['HTTP_STRIPE_SIGNATURE' => StripeSignature::sign($body, 'whsec_x')], $body)->assertOk();

        $this->assertSame('u_2', Conversion::sole()->uid);
        $this->assertSame('sub_u', StripeEvents::uidOf(['parent' => ['subscription_details' => ['metadata' => ['uid' => 'sub_u']]]]));
        $this->assertSame('meta_u', StripeEvents::uidOf(['client_reference_id' => 'ref_u', 'metadata' => ['uid' => 'meta_u']]));
        $this->assertNull(StripeEvents::uidOf(['client_reference_id' => 'not valid!']));
    }

    public function test_the_overview_shows_the_signed_in_users(): void
    {
        $this->writeLog([
            $this->line('2026-10-22 11:00:00', '198.51.100.2', '/docs/', 'u_2'),
            $this->line('2026-10-22 11:00:01', '198.51.100.2', '/app.css', 'u_2'),
        ]);
        $this->collect('2026-10-22');
        $owner = User::factory()->create();
        $this->site->team->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->get(route('teams.sites.show', [$this->site->team, $this->site]).'?days=1')
            ->assertOk()
            ->assertSee('Signed-in users')
            ->assertSee('1 visitors were signed in');
    }
}
