<?php

namespace Tests\Feature;

use App\Analytics\Insights\Insights;
use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Enums\TrafficGroup;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-25 12:00:00');
        $this->site = Site::factory()->create(['timezone' => 'UTC']);
    }

    /**
     * @param  array<string, array<string, int>>  $counts
     */
    private function storeDay(string $day, int $readers, array $counts, DayStatus $status = DayStatus::Verified): void
    {
        $siteDay = $this->site->days()->create(['day' => $day, 'timezone' => 'UTC', 'status' => $status]);
        $siteDay->groups()->create(['group' => TrafficGroup::People, 'readers' => $readers, 'requests' => $readers * 3, 'views' => $readers, 'visits' => $readers]);

        foreach ($counts as $kind => $names) {
            foreach ($names as $name => $count) {
                $siteDay->counts()->create(['kind' => $kind, 'name' => $name, 'count' => $count]);
            }
        }
    }

    /**
     * 14 whole days up to yesterday (24 Oct): /pricing/ read by 2 a day the
     * first week and 4 the second, LinkedIn sending 1 a day then none, and
     * yesterday 9 server errors, a new country and a 404 linked from outside.
     */
    private function history(): void
    {
        foreach (range(13, 0) as $back) {
            $day = CarbonImmutable::parse('2026-10-24')->subDays($back)->toDateString();
            $secondWeek = $back < 7;
            $yesterday = $back === 0;

            $this->storeDay($day, 20, [
                CountKind::PeoplePageVisitors->value => ['/pricing/' => $secondWeek ? 4 : 2, '/' => 10],
                CountKind::PeopleReferrer->value => $secondWeek ? [] : ['linkedin.com' => 1],
                CountKind::PeopleCountry->value => ['CA' => 20, ...($yesterday ? ['DE' => 3] : [])],
                CountKind::HealthStatus->value => ['people:5xx' => $yesterday ? 9 : 0],
                CountKind::HealthNotFoundFrom->value => $yesterday ? ["/old-post\tnews.example.org" => 2] : [],
            ]);
        }
    }

    public function test_it_finds_what_works_dropped_is_new_unusual_and_worth_fixing(): void
    {
        $this->history();

        $found = collect((new Insights($this->site))->all())->map(fn (array $finding) => "{$finding['type']} {$finding['window']} {$finding['kind']} {$finding['name']} {$finding['before']} {$finding['after']}");

        $this->assertContains('working 7 page /pricing/ 14 28', $found);
        $this->assertContains('gone 7 referrer linkedin.com 7 0', $found);
        $this->assertContains('new 1 country DE  3', $found);
        $this->assertContains('anomaly 1 site server_errors 0 9', $found);
        $this->assertContains('opportunity 30 path /old-post  2', $found);
        $this->assertNotContains('working 7 page / 70 70', $found);
    }

    public function test_a_period_with_a_withheld_day_is_not_compared(): void
    {
        $this->history();
        $this->site->days()->whereDate('day', '2026-10-15')->update(['status' => DayStatus::Withheld]);

        $types = collect((new Insights($this->site))->all())->where('window', '7')->pluck('type');

        $this->assertNotContains('working', $types);
        $this->assertNotContains('gone', $types);
    }

    public function test_the_insights_tab_shows_the_cards(): void
    {
        $this->history();
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);
        $tab = fn (array $query = []) => $this->actingAs($viewer)->get(route('teams.sites.insights', [$this->site->team, $this->site, ...$query]))->assertOk();

        $tab()->assertSee('/pricing/: visitors up 100% week on week')->assertSee('visitors, was 14')->assertSee('linkedin.com: no visitors in the last 7 days');
        $tab(['window' => '1'])->assertSee('New country: 🇩🇪 DE')->assertSee('Server errors: spike on Sat 24 Oct');
        $tab(['window' => '30'])->assertSee('/old-post: people reached this missing page from a link elsewhere: redirect it');
    }

    public function test_without_history_it_says_so(): void
    {
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('teams.sites.insights', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('No insights yet');
    }
}
