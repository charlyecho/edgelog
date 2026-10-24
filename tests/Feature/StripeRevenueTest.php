<?php

namespace Tests\Feature;

use App\Enums\ConversionAction;
use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Enums\IntegrationTool;
use App\Ingest\IngestLog;
use App\Integrations\Money;
use App\Integrations\Stripe\StripeEvents;
use App\Integrations\Stripe\StripeSignature;
use App\Jobs\AuditSiteDay;
use App\Jobs\CollectSiteDay;
use App\Models\Conversion;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeRevenueTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private Site $site;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-stripe-'.bin2hex(random_bytes(6))]);
        $this->travelTo('2026-10-22 12:00:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com']);
        $this->site->integrations()->create(['tool' => IntegrationTool::Stripe, 'secret' => self::SECRET]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function event(string $type, array $object, string $at = '2026-10-21 10:00:00', bool $live = true): array
    {
        return [
            'id' => 'evt_'.++$this->sequence,
            'object' => 'event',
            'type' => $type,
            'created' => strtotime("{$at} UTC"),
            'livemode' => $live,
            'data' => ['object' => $object],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function send(array $event, ?string $secret = self::SECRET, ?Site $site = null): TestResponse
    {
        $body = json_encode($event);
        $headers = $secret === null ? [] : ['HTTP_STRIPE_SIGNATURE' => StripeSignature::sign($body, $secret)];

        return $this->call('POST', route('hooks.stripe', $site ?? $this->site), [], [], [], ['CONTENT_TYPE' => 'application/json', ...$headers], $body);
    }

    private function paidSession(string $id = 'cs_1', int $amount = 4900, string $currency = 'eur', string $mode = 'payment'): array
    {
        return ['object' => 'checkout.session', 'id' => $id, 'mode' => $mode, 'payment_status' => 'paid', 'amount_total' => $amount, 'currency' => $currency,
            'customer_details' => ['email' => 'buyer@example.com', 'name' => 'A Buyer']];
    }

    public function test_a_paid_checkout_is_a_payment(): void
    {
        $this->send($this->event('checkout.session.completed', $this->paidSession()))->assertOk()->assertJson(['ok' => true, 'duplicate' => false]);

        $conversion = Conversion::sole();
        $this->assertSame(ConversionAction::Paid, $conversion->action);
        $this->assertSame(4900, $conversion->amount);
        $this->assertSame('eur', $conversion->currency);
        $this->assertNotNull($this->site->integrations()->first()->last_received_at);
    }

    public function test_no_customer_detail_is_kept(): void
    {
        $this->send($this->event('checkout.session.completed', $this->paidSession()));

        $this->assertStringNotContainsString('buyer@example.com', json_encode($this->site->hookEvents()->sole()->payload));
    }

    public function test_an_event_sent_again_is_kept_once(): void
    {
        $event = $this->event('checkout.session.completed', $this->paidSession());

        $this->send($event)->assertOk();
        $this->send($event)->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, Conversion::count());
    }

    public function test_a_bad_signature_is_refused_and_noted(): void
    {
        $this->send($this->event('checkout.session.completed', $this->paidSession()), 'whsec_wrong')->assertUnauthorized();
        $this->send($this->event('checkout.session.completed', $this->paidSession()), null)->assertUnauthorized();

        $this->assertSame(0, Conversion::count());
        $this->assertStringStartsWith('401', $this->site->integrations()->first()->last_refusal);
    }

    public function test_an_old_signature_is_refused(): void
    {
        $body = json_encode($this->event('checkout.session.completed', $this->paidSession()));

        $this->call('POST', route('hooks.stripe', $this->site), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => StripeSignature::sign($body, self::SECRET, time() - 600),
        ], $body)->assertUnauthorized();
    }

    public function test_a_site_without_stripe_answers_404(): void
    {
        $this->send($this->event('checkout.session.completed', $this->paidSession()), self::SECRET, Site::factory()->create())->assertNotFound();
    }

    public function test_other_event_types_are_taken_and_ignored(): void
    {
        $this->send($this->event('customer.created', ['object' => 'customer', 'id' => 'cus_1']))->assertOk()->assertJson(['ignored' => true]);

        $this->assertSame(0, $this->site->hookEvents()->count());
    }

    public function test_a_subscription_counts_its_invoices_not_its_checkout(): void
    {
        $this->send($this->event('checkout.session.completed', $this->paidSession('cs_sub', 1500, 'eur', 'subscription')));
        $this->send($this->event('invoice.paid', ['object' => 'invoice', 'id' => 'in_1', 'amount_paid' => 1500, 'currency' => 'eur',
            'parent' => ['subscription_details' => ['subscription' => 'sub_1']]]));
        $this->send($this->event('invoice.paid', ['object' => 'invoice', 'id' => 'in_2', 'amount_paid' => 1500, 'currency' => 'eur', 'subscription' => 'sub_1']));
        $this->send($this->event('invoice.paid', ['object' => 'invoice', 'id' => 'in_one_off', 'amount_paid' => 900, 'currency' => 'eur']));

        $this->assertSame(['in_1', 'in_2'], Conversion::orderBy('external_ref')->pluck('external_ref')->all());
    }

    public function test_a_delayed_payment_counts_once_it_succeeds(): void
    {
        $session = ['payment_status' => 'unpaid'] + $this->paidSession();
        $this->send($this->event('checkout.session.completed', $session));
        $this->assertSame(0, Conversion::count());

        $this->send($this->event('checkout.session.async_payment_succeeded', $this->paidSession()));
        $this->assertSame(4900, Conversion::sole()->amount);
    }

    public function test_a_refund_counts_once_it_succeeded(): void
    {
        $refund = ['object' => 'refund', 'id' => 're_1', 'amount' => 1000, 'currency' => 'eur'];
        $this->send($this->event('refund.created', ['status' => 'pending'] + $refund));
        $this->send($this->event('refund.updated', ['status' => 'succeeded'] + $refund));
        $this->send($this->event('refund.updated', ['status' => 'succeeded'] + $refund));

        $conversion = Conversion::sole();
        $this->assertSame(ConversionAction::Refunded, $conversion->action);
        $this->assertSame(-1000, $conversion->amount);
    }

    public function test_the_day_counts_live_revenue_by_currency_and_the_audit_agrees(): void
    {
        $this->writeLog();
        $this->send($this->event('checkout.session.completed', $this->paidSession('cs_1', 4900)));
        $this->send($this->event('checkout.session.completed', $this->paidSession('cs_2', 2000)));
        $this->send($this->event('checkout.session.completed', $this->paidSession('cs_3', 3000, 'usd')));
        $this->send($this->event('refund.created', ['object' => 'refund', 'id' => 're_1', 'amount' => 500, 'currency' => 'eur', 'status' => 'succeeded']));
        $this->send($this->event('checkout.session.completed', $this->paidSession('cs_test', 99999), live: false));
        $this->send($this->event('checkout.session.completed', $this->paidSession('cs_other_day', 7000), '2026-10-20 10:00:00'));

        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => '2026-10-21', '--sync' => true])->assertSuccessful();

        $day = $this->site->days()->with('counts')->sole();
        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $counts = fn (CountKind $kind) => $day->counts->where('kind', $kind)->pluck('count', 'name')->sortKeys()->all();
        $this->assertSame(['eur' => 6900, 'usd' => 3000], $counts(CountKind::RevenuePaid));
        $this->assertSame(['eur' => 500], $counts(CountKind::RevenueRefunded));
        $this->assertSame(['eur' => 2, 'usd' => 1], $counts(CountKind::Payments));
    }

    public function test_a_refund_seen_again_the_next_day_is_not_counted_twice(): void
    {
        $this->writeLog();
        $refund = ['object' => 'refund', 'id' => 're_1', 'amount' => 500, 'currency' => 'eur', 'status' => 'succeeded'];
        $this->send($this->event('refund.created', $refund, '2026-10-20 23:00:00'));
        $this->send($this->event('refund.updated', $refund, '2026-10-21 09:00:00'));

        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => '2026-10-21', '--sync' => true])->assertSuccessful();

        $day = $this->site->days()->with('counts')->sole();
        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $this->assertSame([], $day->counts->where('kind', CountKind::RevenueRefunded)->all());
    }

    public function test_a_late_payment_counts_its_day_again(): void
    {
        $this->writeLog();
        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => '2026-10-21', '--sync' => true]);
        Bus::fake([CollectSiteDay::class, AuditSiteDay::class]);

        $this->send($this->event('checkout.session.completed', $this->paidSession()));

        Bus::assertChained([CollectSiteDay::class, AuditSiteDay::class]);
    }

    public function test_the_secret_is_saved_encrypted(): void
    {
        $owner = User::factory()->create();
        $this->site->team->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->put(route('teams.sites.stripe.update', [$this->site->team, $this->site]), ['secret' => 'not-a-secret'])
            ->assertSessionHasErrors('secret');

        $this->actingAs($owner)
            ->put(route('teams.sites.stripe.update', [$this->site->team, $this->site]), ['secret' => 'whsec_new'])
            ->assertSessionHasNoErrors();

        $this->assertSame('whsec_new', $this->site->integrations()->sole()->secret);
        $this->assertDatabaseMissing('site_integrations', ['secret' => 'whsec_new']);
    }

    public function test_viewers_cannot_connect_stripe(): void
    {
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->put(route('teams.sites.stripe.update', [$this->site->team, $this->site]), ['secret' => 'whsec_new'])
            ->assertForbidden();
    }

    public function test_the_overview_shows_the_revenue(): void
    {
        $this->writeLog();
        $this->send($this->event('checkout.session.completed', $this->paidSession('cs_1', 4900)));
        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => '2026-10-21', '--sync' => true]);
        $owner = User::factory()->create();
        $this->site->team->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->get(route('teams.sites.show', [$this->site->team, $this->site]).'?days=1')
            ->assertOk()
            ->assertSee('Revenue')
            ->assertSee(Money::format(4900, 'eur'))
            ->assertSee('1 payment');
    }

    public function test_amounts_follow_the_currencys_minor_unit(): void
    {
        $this->assertSame('€49.00', Money::format(4900, 'eur', 'en'));
        $this->assertSame('¥4,900', Money::format(4900, 'jpy', 'en'));
        $this->assertSame(3, Money::decimals('kwd'));
        $this->assertNull(StripeEvents::conversion(['type' => 'invoice.paid', 'created' => 1, 'data' => ['object' => ['id' => 'in_x', 'currency' => 'eur', 'amount_paid' => 0, 'subscription' => 'sub_1']]]));
    }

    private function writeLog(): void
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/access.log", implode("\n", [
            '198.51.100.1 - - [20/Oct/2026:10:00:00 +0000] "GET / HTTP/1.1" 200 5 "-" "curl/8"',
            '198.51.100.1 - - [21/Oct/2026:10:00:00 +0000] "GET / HTTP/1.1" 200 5 "-" "curl/8"',
        ])."\n");
    }
}
