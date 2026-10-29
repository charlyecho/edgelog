<?php

namespace App\Analytics\Security;

use App\Analytics\SitePeriod;
use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Models\SiteDay;
use App\Models\SiteDayAttack;
use App\Models\SiteDaySecurity;
use App\Models\SiteDaySuspect;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A period's security, read back from its verified days: the figures summed,
 * each host's answers, the possible exposures, and the suspects by address.
 * Suspects' addresses are kept analytics.security.kept_days, so the lists
 * by address reach back only that far; the figures stay.
 */
final class SecurityPeriod
{
    /**
     * Each verified day's security, by day.
     *
     * @var Collection<string, SiteDaySecurity>
     */
    public readonly Collection $days;

    public function __construct(public readonly SitePeriod $period)
    {
        $ids = $period->verified()->mapWithKeys(fn (SiteDay $day, string $date) => [$day->id => $date]);

        $this->days = SiteDaySecurity::query()->whereIn('site_day_id', $ids->keys())->get()
            ->keyBy(fn (SiteDaySecurity $security) => $ids[$security->site_day_id])
            ->sortKeys();
    }

    /**
     * Determine if every verified day was counted with its security (days
     * counted before it was kept have none).
     */
    public function isWhole(): bool
    {
        return $this->period->hasFigures() && $this->days->count() === $this->period->verified()->count();
    }

    public function figure(string $name): int
    {
        return (int) $this->days->sum(fn (SiteDaySecurity $security) => $security->figures[$name] ?? 0);
    }

    /**
     * Each host's probes for files answered with a page, summed over the days.
     *
     * @return array<string, array{bare_ip: bool, answered: int, shells: list<int>, shell: int, empty: int, possible: int, unknown: int}>
     */
    public function hosts(): array
    {
        $hosts = [];

        foreach ($this->days as $security) {
            foreach ($security->hosts as $host => $row) {
                $sum = $hosts[$host] ?? ['bare_ip' => $row['bare_ip'], 'answered' => 0, 'shells' => [], 'shell' => 0, 'empty' => 0, 'possible' => 0, 'unknown' => 0];

                foreach (['answered', 'shell', 'empty', 'possible', 'unknown'] as $figure) {
                    $sum[$figure] += $row[$figure];
                }

                $sum['shells'] = array_values(array_unique([...$sum['shells'], ...$row['shells']]));
                $hosts[$host] = $sum;
            }
        }

        uasort($hosts, fn (array $a, array $b) => $b['answered'] <=> $a['answered']);

        return $hosts;
    }

    /**
     * The answers worth a look, possible exposures first, newest first.
     *
     * @return list<array{host: string, path: string, status: int, size: ?int, at: int, verdict: string}>
     */
    public function exposures(): array
    {
        $exposures = $this->days->flatMap(fn (SiteDaySecurity $security) => $security->exposures)->all();
        usort($exposures, fn (array $a, array $b) => [$a['verdict'] === 'possible' ? 0 : 1, -$a['at']] <=> [$b['verdict'] === 'possible' ? 0 : 1, -$b['at']]);

        return $exposures;
    }

    /**
     * @return Builder<SiteDaySuspect>
     */
    public function suspects(): Builder
    {
        return SiteDaySuspect::query()->whereIn('site_day_id', $this->period->verified()->pluck('id'));
    }

    /**
     * @return Builder<SiteDayAttack>
     */
    public function attacks(string $kind): Builder
    {
        return SiteDayAttack::query()->whereIn('site_day_id', $this->period->verified()->pluck('id'))->where('kind', $kind)->orderByDesc('requests');
    }

    /**
     * The paths probed most over the period, of one kind or any: how often,
     * and what the server answered them (by class, 2xx first; empty for the
     * days counted before answers were kept by path).
     *
     * @return list<array{path: string, category: string, probes: int, answered: array<string, int>}>
     */
    public function probedPaths(?string $category = null, int $rows = 500): array
    {
        $answered = [];

        foreach ($this->period->counts(CountKind::SecurityPathAnswer) as $name => $count) {
            [$path, $class] = array_pad(explode(SecurityNames::ANSWER_SEPARATOR, (string) $name, 2), 2, null);

            if ($class !== null) {
                $answered[$path][$class] = ($answered[$path][$class] ?? 0) + $count;
            }
        }

        $paths = [];

        foreach ($this->period->counts(CountKind::SecurityPath) as $path => $count) {
            $path = (string) $path;
            $kind = SecurityNames::category($path, $path);

            if ($path === CountKind::OTHERS || ($category !== null && $kind !== $category)) {
                continue;
            }

            $classes = $answered[$path] ?? [];
            ksort($classes);
            $paths[] = ['path' => $path, 'category' => $kind, 'probes' => $count, 'answered' => $classes];
        }

        usort($paths, fn (array $a, array $b) => [$b['probes'], $a['path']] <=> [$a['probes'], $b['path']]);

        return array_slice($paths, 0, $rows);
    }

    /**
     * The suspects summed by address, worst first: probes, then attack
     * attempts, then requests. Read as plain rows, their lists decoded once:
     * a month holds thousands of them.
     *
     * @param  ?callable(object): bool  $keep  given each row, with its lists decoded
     * @return list<array{address: string, country: string, first_seen_at: CarbonImmutable, requests: int, probes: int, attempts: int, fake_requests: int, categories: array<string, int>, answered: array<string, int>, paths: array<string, int>, signs: list<string>, days: int}>
     */
    public function byAddress(?callable $keep = null): array
    {
        $rows = [];

        foreach ($this->suspects()->toBase()->get() as $suspect) {
            foreach (['categories', 'answered', 'paths', 'signs'] as $list) {
                $suspect->{$list} = json_decode((string) $suspect->{$list}, true) ?? [];
            }

            if ($keep !== null && ! $keep($suspect)) {
                continue;
            }

            $row = $rows[$suspect->address] ?? ['address' => $suspect->address, 'country' => $suspect->country, 'first_seen_at' => $suspect->first_seen_at,
                'requests' => 0, 'probes' => 0, 'attempts' => 0, 'fake_requests' => 0, 'categories' => [], 'answered' => [], 'paths' => [], 'signs' => [], 'days' => [], 'attempted' => []];

            // Stored as Y-m-d H:i:s, so the earliest is the smallest string.
            $row['first_seen_at'] = min($row['first_seen_at'], $suspect->first_seen_at);
            $row['days'][$suspect->site_day_id] = true;

            foreach (['requests', 'probes', 'fake_requests'] as $figure) {
                $row[$figure] += (int) $suspect->{$figure};
            }

            // An address's attempts are on each of its rows of the day: once a day.
            $row['attempts'] += isset($row['attempted'][$suspect->site_day_id]) ? 0 : (int) $suspect->attempts;
            $row['attempted'][$suspect->site_day_id] = true;

            foreach (['categories', 'answered', 'paths'] as $list) {
                foreach ($suspect->{$list} as $name => $count) {
                    $row[$list][$name] = ($row[$list][$name] ?? 0) + $count;
                }
            }

            foreach ($suspect->signs as $sign) {
                $row['signs'][$sign] = true;
            }

            $rows[$suspect->address] = $row;
        }

        $rows = array_map(function (array $row) {
            arsort($row['categories']);
            arsort($row['paths']);
            unset($row['attempted']);

            return [...$row, 'first_seen_at' => CarbonImmutable::parse($row['first_seen_at']), 'signs' => array_keys($row['signs']), 'days' => count($row['days'])];
        }, array_values($rows));

        usort($rows, fn (array $a, array $b) => [$b['probes'], $b['attempts'], $b['requests']] <=> [$a['probes'], $a['attempts'], $a['requests']]);

        return $rows;
    }

    /**
     * The days whose gateway errors or .php requests stand out: at least
     * three times the median of the 14 days before (with 7 of them
     * counted), and at least 20. Worth a look, never proof of an attack.
     *
     * @return list<array{day: string, figure: string, value: int, median: int}>
     */
    public function signs(): array
    {
        $before = SiteDay::query()
            ->where('site_id', $this->period->site->id)
            ->where('status', DayStatus::Verified)
            ->whereBetween('day', [$this->period->first->subDays(14)->toDateString(), $this->period->last->toDateString()])
            ->with('security')
            ->get()
            ->filter(fn (SiteDay $day) => $day->security !== null)
            ->mapWithKeys(fn (SiteDay $day) => [$day->day->toDateString() => $day->security->figures]);

        $signs = [];

        foreach ($this->days->keys() as $date) {
            foreach (['gateway', 'php'] as $figure) {
                $earlier = $before->filter(fn ($figures, string $day) => $day < $date && $day >= CarbonImmutable::parse($date)->subDays(14)->toDateString())
                    ->map(fn (array $figures) => $figures[$figure] ?? 0)->sort()->values();

                if ($earlier->count() < 7) {
                    continue;
                }

                $median = (int) $earlier->median();
                $value = (int) ($before[$date][$figure] ?? 0);

                if ($value >= 20 && $value >= 3 * $median) {
                    $signs[] = ['day' => $date, 'figure' => $figure, 'value' => $value, 'median' => $median];
                }
            }
        }

        return $signs;
    }
}
