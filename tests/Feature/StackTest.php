<?php

namespace Tests\Feature;

use App\Analytics\SiteAlerts;
use App\Analytics\Stack\CertificateReader;
use App\Jobs\CheckStack;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StackTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    /**
     * The certificate each host shows, by host.
     *
     * @var array<string, ?array<string, mixed>>
     */
    private array $certificates = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-23 08:00:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com']);

        $this->certificates = [
            'example.com' => ['expires_at' => '2026-12-20T12:00:00Z', 'issuer' => "Let's Encrypt", 'names' => ['example.com'], 'trusted' => true],
            'example.org' => null,
        ];
        $this->mock(CertificateReader::class, fn ($mock) => $mock->shouldReceive('read')->andReturnUsing(fn (string $host) => $this->certificates[$host] ?? null));

        Http::fake([
            'example.com/' => Http::response('', 302, ['Location' => '/home', 'Server' => 'nginx/1.24.0', 'X-Powered-By' => 'PHP/8.1.2', 'Set-Cookie' => 'PHPSESSID=abc; path=/']),
            'example.com/home' => Http::response('<meta name="generator" content="WordPress 6.4.2"><link href="https://example.org/wp-content/themes/a.css">', 200, ['Server' => 'nginx']),
            'example.com/wp-login.php' => Http::response('', 200),
            'example.org/' => Http::response('ok', 200, ['Server' => 'Apache/2.4.69']),
            'example.org/wp-login.php' => Http::response('', 403),
            'endoflife.date/api/nginx.json' => Http::response([['cycle' => '1.31', 'eol' => false, 'latest' => '1.31.8'], ['cycle' => '1.24', 'eol' => true, 'latest' => '1.24.0']]),
            'endoflife.date/api/php.json' => Http::response([['cycle' => '8.5', 'eol' => '2029-12-31', 'latest' => '8.5.12'], ['cycle' => '8.1', 'eol' => '2025-12-31', 'latest' => '8.1.34']]),
            'endoflife.date/api/wordpress.json' => Http::response([['cycle' => '6.4', 'eol' => false, 'latest' => '6.4.5']]),
            'endoflife.date/api/apache-http-server.json' => Http::response([['cycle' => '2.4', 'eol' => false, 'latest' => '2.4.69']]),
        ]);
    }

    public function test_the_stack_is_read_from_outside_and_set_against_each_branch(): void
    {
        CheckStack::dispatchSync($this->site);

        [$site, $origin] = $this->site->stack()->first()->hosts;
        $status = fn (array $host) => array_map(fn (array $found) => [$found['version'], $found['status']], $host['products']);

        $this->assertSame([
            'nginx' => ['1.24.0', 'eol'],
            'php' => ['8.1.2', 'eol'],
            'wordpress' => ['6.4.2', 'update'],
        ], $status($site));
        $this->assertSame('open', $site['login']);
        $this->assertSame(['example.org', 'origin'], [$origin['host'], $origin['role']]);
        $this->assertSame(['apache' => ['2.4.69', 'current']], $status($origin));
    }

    public function test_the_page_says_what_to_update_and_what_to_check(): void
    {
        CheckStack::dispatchSync($this->site);
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('teams.sites.security.stack', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('Branch no longer supported')
            ->assertSee('PHP 8.1.2 on example.com')
            ->assertSee('6.4.5 is out in the 6.4 branch')
            ->assertSee('Login page open to the internet');

        $this->actingAs($viewer)->post(route('teams.sites.security.stack.refresh', [$this->site->team, $this->site]))->assertForbidden();
    }

    public function test_each_hosts_certificate_is_read_and_one_ending_soon_is_an_alert(): void
    {
        CheckStack::dispatchSync($this->site);

        [$site, $origin] = $this->site->stack()->first()->hosts;
        $this->assertSame(['2026-12-20T12:00:00Z', true], [$site['certificate']['expires_at'], $site['certificate']['trusted']]);
        $this->assertNull($origin['certificate']);
        $this->assertSame([], $this->certificateAlerts(), 'Two months ahead is no alert.');

        $this->travelTo('2026-12-05 08:00:00');
        $this->assertSame([['warn', 'Certificate ends in 15 days']], $this->certificateAlerts());

        $this->travelTo('2026-12-18 08:00:00');
        $this->assertSame([['bad', 'Certificate ends in 2 days']], $this->certificateAlerts());

        $this->travelTo('2026-12-21 08:00:00');
        $this->assertSame([['bad', 'Certificate expired']], $this->certificateAlerts());
    }

    public function test_a_certificate_browsers_do_not_trust_is_an_alert_and_shows_on_the_page(): void
    {
        $this->certificates['example.com']['trusted'] = false;
        CheckStack::dispatchSync($this->site);

        $this->assertSame([['bad', 'Certificate not trusted']], $this->certificateAlerts());

        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->get(route('teams.sites.security.stack', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('TLS certificate')
            ->assertSee('ends on 2026-12-20')
            ->assertSee('not trusted by browsers');
    }

    /**
     * The site's alerts about its certificate, as [tone, label].
     *
     * @return list<array{0: string, 1: string}>
     */
    private function certificateAlerts(): array
    {
        return collect(SiteAlerts::for(collect([$this->site->fresh()]))[$this->site->id])
            ->filter(fn (array $alert) => str_starts_with($alert['label'], 'Certificate'))
            ->map(fn (array $alert) => [$alert['tone'], $alert['label']])
            ->values()
            ->all();
    }
}
