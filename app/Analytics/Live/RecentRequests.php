<?php

namespace App\Analytics\Live;

use App\Analytics\Classification\Classifier;
use App\Analytics\Classification\Verdict;
use App\Analytics\Classification\VisitEvidence;
use App\Analytics\DayWindow;
use App\Analytics\Geo\Places;
use App\Analytics\LogWindowReader;
use App\Analytics\NotPages;
use App\Analytics\OwnPaths;
use App\Analytics\PageRule;
use App\Analytics\SiteHosts;
use App\Analytics\Sources;
use App\Analytics\TimeOrdered;
use App\Analytics\UserAgentLabels;
use App\Enums\EngineTier;
use App\Enums\TrafficGroup;
use App\Ingest\IngestLog;
use App\Ingest\LogHit;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Every request of the last few hours, newest first, for Site > Requests:
 * each with the group and the rule that put it there. The log is read from
 * the start of the site's day the window begins in, so a visit is judged
 * whole, with the same rules as the daily count. Nothing of it is stored:
 * the rows hold raw addresses, and live only in the cache for a minute.
 */
final class RecentRequests
{
    public const int HOURS = 6;

    /**
     * The most rows kept, newest first.
     */
    public const int CAP = 5000;

    public const int KEEP_SECONDS = 60;

    public function __construct(private readonly Places $places) {}

    /**
     * The window's rows and requests by group, kept a minute.
     *
     * @return array{from: int, until: int, total: int, by_group: array<string, int>, rows: list<array<string, mixed>>}
     */
    public static function latest(Site $site): array
    {
        return Cache::remember("site-requests:{$site->id}", self::KEEP_SECONDS, fn () => app(self::class)->collect($site));
    }

    /**
     * @return array{from: int, until: int, total: int, by_group: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function collect(Site $site): array
    {
        $now = now()->toImmutable();
        $from = $now->subHours(self::HOURS);
        $window = new DayWindow($from->setTimezone($site->timezone)->toDateString(), $site->timezone, $now->addSecond());
        $hosts = SiteHosts::of($site);
        $reader = new LogWindowReader(IngestLog::for($site)->folder(), $site->log_format->parser(), $window);
        $notPages = NotPages::of($site);
        $ownPaths = OwnPaths::of($site);

        $evidence = new VisitEvidence($hosts->hosts, (bool) config('analytics.require_rendering'), (int) config('analytics.visit_gap_minutes') * 60,
            (int) config('analytics.too_fast_pages'), (int) config('analytics.too_fast_seconds'), $notPages, $ownPaths);

        foreach ($reader->hits() as $hit) {
            if ($hosts->covers($hit)) {
                $evidence->add($hit);
            }
        }

        $evidence->finish();
        $classifier = new Classifier($evidence, $ownPaths);
        $sources = new Sources($hosts->hosts);
        $byGroup = array_fill_keys(array_column(TrafficGroup::cases(), 'value'), 0);
        $rows = [];

        foreach (TimeOrdered::of($reader->hits(), 120) as $hit) {
            if (! $hosts->covers($hit) || $hit->when->lessThan($from)) {
                continue;
            }

            $verdict = $classifier->classify($hit);
            $byGroup[$verdict->group->value]++;
            $rows[] = $this->row($hit, $verdict, PageRule::isPage($hit, $notPages), $sources);

            if (count($rows) > self::CAP * 2) {
                $rows = array_slice($rows, -self::CAP);
            }
        }

        return [
            'from' => $from->getTimestamp(),
            'until' => $now->getTimestamp(),
            'total' => array_sum($byGroup),
            'by_group' => $byGroup,
            'rows' => array_reverse(array_slice($rows, -self::CAP)),
        ];
    }

    /**
     * Why a row's request is in its group, in words.
     *
     * @param  array<string, mixed>  $row
     */
    public static function rule(array $row): string
    {
        return match (TrafficGroup::from($row['group'])) {
            TrafficGroup::People => match (true) {
                $row['page'] && $row['cited'] !== '' => __('people: page view from :assistant', ['assistant' => $row['cited']]),
                $row['page'] => __('people: page view'),
                $row['method'] === 'POST' => __('people: form post'),
                default => __('people: file'),
            },
            TrafficGroup::AnswerEngines => $row['tier'] === EngineTier::Asked->value
                ? __('AI asked: :name', ['name' => $row['name']])
                : __('AI training: :name', ['name' => $row['name']]),
            TrafficGroup::SearchEngines => __('search: :name', ['name' => $row['name']]),
            TrafficGroup::Bots => __('bot: :name', ['name' => $row['name']]),
            TrafficGroup::Ours => __('ours: :name', ['name' => $row['name']]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LogHit $hit, Verdict $verdict, bool $isPage, Sources $sources): array
    {
        $referrerHost = Sources::referrerHost($hit->referrer);

        return [
            'at' => $hit->when->getTimestamp(),
            'status' => $hit->status,
            'method' => $hit->method,
            'path' => substr($hit->path(), 0, 300) ?: '/',
            'group' => $verdict->group->value,
            'name' => $verdict->name,
            'tier' => $verdict->tier?->value,
            'page' => $isPage,
            'probe' => $verdict->probe,
            'address' => $hit->address,
            'country' => $this->places->of($hit->address, $hit->country)->country,
            'referrer' => $referrerHost === '' ? '' : $referrerHost.(parse_url($hit->referrer, PHP_URL_PATH) ?: ''),
            'cited' => $verdict->citedBy,
            'own_referrer' => $sources->referrerLabel($hit->referrer) === Sources::THIS_SITE,
            'agent' => $verdict->group === TrafficGroup::People ? UserAgentLabels::browser($hit->userAgent) : ($verdict->name ?: UserAgentLabels::browser($hit->userAgent)),
            'system' => UserAgentLabels::system($hit->userAgent),
            'device' => UserAgentLabels::device($hit->userAgent),
            'user_agent' => substr($hit->userAgent, 0, 300),
            'ms' => $hit->requestTime === null ? null : (int) round($hit->requestTime * 1000),
        ];
    }
}
