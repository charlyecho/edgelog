<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Jobs\FetchSiteIcon;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SiteIconTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    private Site $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->site = Site::factory()->create(['url' => 'https://example.com']);
        $this->owner = User::factory()->create();
        $this->site->team->members()->attach($this->owner, ['role' => 'owner']);
    }

    public function test_the_icon_is_read_from_the_icons_the_home_page_names(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><head><link rel="shortcut icon" href="/favicon.ico"><link rel="apple-touch-icon" sizes="180x180" href="img/touch.png"><link rel="mask-icon" href="/mask.svg"></head></html>'),
            'example.com/img/touch.png' => Http::response(self::PNG),
            '*' => Http::response('', 404),
        ]);

        $this->assertTrue((new FetchSiteIcon($this->site))->handle());

        $this->site->refresh();
        $this->assertSame("site-icons/{$this->site->uuid}.png", $this->site->icon_path);
        $this->assertFalse($this->site->icon_uploaded);
        Storage::disk('local')->assertExists($this->site->icon_path);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'mask.svg'));
    }

    public function test_favicon_ico_is_read_when_the_page_names_no_icon(): void
    {
        Http::fake([
            'example.com' => Http::response('<html><head></head></html>'),
            'example.com/favicon.ico' => Http::response(self::PNG),
        ]);

        $this->assertTrue((new FetchSiteIcon($this->site))->handle());
        $this->assertNotNull($this->site->fresh()->icon_path);
    }

    public function test_an_answer_that_is_not_an_image_is_not_kept(): void
    {
        Http::fake(['*' => Http::response('<html>Not found</html>')]);

        $this->assertFalse((new FetchSiteIcon($this->site))->handle());
        $this->assertNull($this->site->fresh()->icon_path);
    }

    public function test_redirects_to_a_private_address_are_not_followed(): void
    {
        Http::fake([
            'example.com' => Http::response('', 302, ['Location' => 'http://127.0.0.1/']),
            'example.com/favicon.ico' => Http::response('', 302, ['Location' => 'http://10.0.0.1/favicon.ico']),
        ]);

        $this->assertFalse((new FetchSiteIcon($this->site))->handle());
        Http::assertNotSent(fn ($request) => in_array(parse_url($request->url(), PHP_URL_HOST), ['127.0.0.1', '10.0.0.1'], true));
    }

    public function test_icon_links_are_ranked_drawings_first_then_by_size(): void
    {
        $links = FetchSiteIcon::iconLinks(
            '<link rel="icon" href="/16.png" sizes="16x16"><link rel="icon" href="//cdn.example.com/64.png" sizes="64x64"><link rel="icon" type="image/svg+xml" href="/logo.svg"><link rel="icon" href="data:image/png;base64,AAAA">',
            'https://example.com/blog/',
        );

        $this->assertSame(['https://example.com/logo.svg', 'https://cdn.example.com/64.png', 'https://example.com/16.png'], $links);
    }

    public function test_managers_upload_an_icon_that_members_then_see(): void
    {
        $this->actingAs($this->owner)
            ->post(route('teams.sites.icon.store', [$this->site->team, $this->site]), ['icon' => UploadedFile::fake()->createWithContent('icon.png', self::PNG)])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->site->refresh();
        $this->assertTrue($this->site->icon_uploaded);

        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('teams.sites.index', $this->site->team))->assertSee($this->site->iconUrl($this->site->team), false);
        $this->actingAs($viewer)->get($this->site->iconUrl($this->site->team))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($viewer)->post(route('teams.sites.icon.store', [$this->site->team, $this->site]), ['icon' => UploadedFile::fake()->createWithContent('icon.png', self::PNG)])->assertForbidden();
        $this->actingAs(User::factory()->create())->get($this->site->iconUrl($this->site->team))->assertForbidden();
    }

    public function test_an_upload_that_is_not_an_image_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('teams.sites.icon.store', [$this->site->team, $this->site]), ['icon' => UploadedFile::fake()->createWithContent('icon.png', '<?php echo 1;')])
            ->assertSessionHasErrors('icon');

        $this->assertNull($this->site->fresh()->icon_path);
    }

    public function test_an_uploaded_icon_is_only_replaced_when_asked(): void
    {
        $this->site->storeIcon(self::PNG, uploaded: true);
        Http::fake(['*' => Http::response(self::PNG)]);

        $this->assertFalse((new FetchSiteIcon($this->site))->handle());
        $this->assertTrue($this->site->fresh()->icon_uploaded);

        $this->actingAs($this->owner)->post(route('teams.sites.icon.fetch', [$this->site->team, $this->site]))->assertSessionHas('status', 'Icon read from the site.');
        $this->assertFalse($this->site->fresh()->icon_uploaded);
    }

    public function test_removing_the_icon_or_the_site_deletes_the_file(): void
    {
        $this->site->storeIcon(self::PNG, uploaded: true);
        $path = $this->site->icon_path;

        $this->actingAs($this->owner)->delete(route('teams.sites.icon.destroy', [$this->site->team, $this->site]))->assertRedirect();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($this->site->fresh()->icon_path);

        $this->site->refresh()->storeIcon(self::PNG, uploaded: false);
        $this->site->delete();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_manager_gives_the_team_an_icon_its_members_see(): void
    {
        Storage::fake('local');
        $team = $this->site->team;
        $admin = User::factory()->create();
        $viewer = User::factory()->create();
        $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);
        $team->members()->attach($viewer, ['role' => TeamRole::Viewer->value]);

        $this->actingAs($viewer)->post(route('teams.icon.store', $team), ['icon' => UploadedFile::fake()->createWithContent('icon.png', self::PNG)])->assertForbidden();

        $this->actingAs($admin)
            ->post(route('teams.icon.store', $team), ['icon' => UploadedFile::fake()->createWithContent('icon.png', self::PNG)])
            ->assertSessionHasNoErrors();

        $team->refresh();
        $this->assertSame("team-icons/{$team->uuid}.png", $team->icon_path);
        $this->actingAs($viewer)->get(route('teams.servers.index', $team))->assertSee($team->iconUrl(), false);
        $this->actingAs($viewer)->get($team->iconUrl())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs(User::factory()->create())->get($team->iconUrl())->assertForbidden();

        $this->actingAs($admin)->delete(route('teams.icon.destroy', $team))->assertSessionHasNoErrors();
        $this->assertNull($team->fresh()->icon_path);
        Storage::disk('local')->assertMissing("team-icons/{$team->uuid}.png");
    }

    public function test_a_team_icon_must_be_an_image(): void
    {
        $admin = User::factory()->create();
        $this->site->team->members()->attach($admin, ['role' => TeamRole::Owner->value]);

        $this->actingAs($admin)
            ->post(route('teams.icon.store', $this->site->team), ['icon' => UploadedFile::fake()->createWithContent('icon.png', 'not an image')])
            ->assertSessionHasErrors('icon');

        $this->assertNull($this->site->team->fresh()->icon_path);
    }
}
