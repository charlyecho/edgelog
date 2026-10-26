<?php

namespace Tests\Feature;

use App\Analytics\Geo\WorldMap;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class WorldMapTest extends TestCase
{
    public function test_the_map_has_a_shape_or_a_dot_for_every_country_with_visitors(): void
    {
        $map = WorldMap::shapes();

        foreach (['FR', 'NO', 'US', 'CA', 'RU', 'AU', 'BR', 'JP'] as $code) {
            $this->assertArrayHasKey($code, $map['paths']);
            $this->assertArrayHasKey($code, $map['points']);
        }

        foreach (['SG', 'HK', 'MT', 'LU'] as $code) {
            $this->assertContains($code, $map['dots']);
        }

        $this->assertArrayNotHasKey('AQ', $map['paths']);
    }

    public function test_counts_are_shaded_in_ranges_growing_by_the_same_factor(): void
    {
        $this->assertSame([[1, 3], [4, 6], [7, 16], [17, 40], [41, 100]], WorldMap::ranges(100));
        $this->assertSame([[1, 1]], WorldMap::ranges(1));
        $this->assertSame([], WorldMap::ranges(0));

        $ranges = WorldMap::ranges(100);
        $this->assertSame(0, WorldMap::shade(0, $ranges));
        $this->assertSame(1, WorldMap::shade(2, $ranges));
        $this->assertSame(5, WorldMap::shade(100, $ranges));
        $this->assertSame(3, WorldMap::shade(1, WorldMap::ranges(1)));
    }

    public function test_the_map_names_each_counted_country_and_pulses_the_active_ones(): void
    {
        $html = Blade::render('<x-charts.world-map :countries="$countries" label="Map" legend="Visits today" count=":count visit today|:count visits today" active=":count active|:count active" live />', [
            'countries' => ['FR' => ['count' => 12, 'active' => 2], 'SG' => ['count' => 1, 'active' => 0], 'Unknown' => ['count' => 5, 'active' => 1]],
        ]);

        $this->assertStringContainsString('data-name="France" data-detail="12 visits today · 2 active"', $html);
        $this->assertStringContainsString('data-name="Singapore" data-detail="1 visit today · 0 active"', $html);
        $this->assertSame(3, substr_count($html, 'class="map-ring') + substr_count($html, 'class="map-bubble"'));
        $this->assertStringNotContainsString('Unknown', $html);
    }
}
