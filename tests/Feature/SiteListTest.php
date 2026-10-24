<?php

namespace Tests\Feature;

use App\Analytics\SiteSparklines;
use App\Enums\DayStatus;
use App\Enums\TrafficGroup;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteListTest extends TestCase
{
    use RefreshDatabase;

    private function storeDay(Site $site, string $day, DayStatus $status, int $readers): void
    {
        $siteDay = $site->days()->create(['day' => $day, 'timezone' => $site->timezone, 'status' => $status]);
        $siteDay->groups()->create(['group' => TrafficGroup::People, 'readers' => $readers, 'requests' => $readers, 'views' => $readers, 'visits' => $readers]);
    }

    public function test_each_site_gets_its_last_30_days_of_visitors(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $site = Site::factory()->create();
        $this->storeDay($site, '2026-10-23', DayStatus::Verified, 12);
        $this->storeDay($site, '2026-10-22', DayStatus::Withheld, 99);
        $this->storeDay($site, '2026-10-21', DayStatus::Verified, 7);
        $this->storeDay($site, '2026-09-01', DayStatus::Verified, 50);

        $series = SiteSparklines::visitors(collect([$site]))[$site->id];

        $this->assertCount(30, $series);
        $this->assertSame(12, $series[29]);
        $this->assertNull($series[28], 'a withheld day is never drawn');
        $this->assertSame(7, $series[27]);
        $this->assertNull($series[0]);
    }

    public function test_the_list_shows_the_sparkline_and_the_total(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $user = User::factory()->create();
        $team = Team::factory()->withMember($user)->create();
        $busy = Site::factory()->for($team)->create(['name' => 'busy.example.com']);
        Site::factory()->for($team)->create(['name' => 'quiet.example.com']);
        $this->storeDay($busy, '2026-10-23', DayStatus::Verified, 12);
        $this->storeDay($busy, '2026-10-22', DayStatus::Verified, 8);

        $this->actingAs($user)
            ->get(route('teams.sites.index', $team))
            ->assertOk()
            ->assertSee('20')
            ->assertSee('visitors, last 30 days')
            ->assertSee('2 of 30 days')
            ->assertSee('No figures for the last 30 days yet.');
    }
}
