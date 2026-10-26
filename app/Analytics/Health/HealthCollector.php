<?php

namespace App\Analytics\Health;

use App\Analytics\Classification\Classifier;
use App\Analytics\Classification\Verdict;
use App\Analytics\DayFigures;
use App\Analytics\NotPages;
use App\Analytics\PageRule;
use App\Analytics\Sources;
use App\Enums\CountKind;
use App\Enums\TrafficGroup;
use App\Ingest\LogHit;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * The site's health, for one day: what the server answered each group, its
 * errors, how fast it answered people's page views, the form posts, and
 * whether the site's own heartbeat was answered.
 *
 *  status       every request by group and status class ("people:2xx"),
 *               adding up to the day's requests
 *  code         every request by group and exact status ("people:301"),
 *               adding up to the day's requests too
 *  error page   every 4xx or 5xx answer but a 404 (the Not found tab
 *               has those), by status and page ("403\t/admin")
 *  speed        people's page views by the server's time to answer
 *               ($request_time), "not logged" when the line has none
 *  slow page    a people's page view answered in a second or more
 *  not found    a people's 404, by page and by where they came from (the
 *               referrer's label); an assistant's or search crawler's 404
 *               or 410, by page
 *  heartbeat    the site's server asking for its own page every five
 *               minutes (Patterns::HEARTBEAT): a five-minute slot is up
 *               when a beat in it was answered 2xx or 3xx; a gap is more
 *               than ten minutes without one
 */
final class HealthCollector
{
    /**
     * The status classes, in the order they are shown.
     */
    public const array STATUSES = ['2xx', '3xx', '404', '4xx', '5xx', 'other'];

    /**
     * The answer times, each up to the next one's seconds.
     *
     * @var array<string, float>
     */
    public const array SPEEDS = ['under 0.2 s' => 0.2, '0.2 to 0.5 s' => 0.5, '0.5 to 1 s' => 1.0, '1 to 3 s' => 3.0, '3 s or more' => INF];

    /**
     * The answer times of a slow page view.
     */
    public const array SLOW = ['1 to 3 s', '3 s or more'];

    public const string NOT_LOGGED = 'not logged';

    /**
     * Seconds between two beats, and without an answered one before it is a gap.
     */
    public const int BEAT_SECONDS = 300;

    public const int GAP_SECONDS = 600;

    /**
     * The day's breakdowns: [kind][name] => count.
     *
     * @var array<string, array<string, int>>
     */
    private array $counts = [];

    /**
     * When each answered beat came.
     *
     * @var list<int>
     */
    private array $answeredBeats = [];

    private int $beats = 0;

    public function __construct(private readonly Sources $sources, private readonly NotPages $notPages = new NotPages) {}

    public function add(LogHit $hit, Verdict $verdict): void
    {
        $path = substr($hit->path(), 0, 200) ?: '/';
        $status = self::statusClass($hit->status);

        $this->count(CountKind::HealthStatus, "{$verdict->group->value}:{$status}");
        $this->count(CountKind::HealthCode, "{$verdict->group->value}:{$hit->status}");

        if ($hit->status >= 400 && $hit->status !== 404) {
            $this->count(CountKind::HealthErrorPage, "{$hit->status}\t{$path}");
        }

        if ($status === '5xx') {
            $this->count(CountKind::HealthServerError, $path);
        }

        if ($hit->method === 'POST') {
            $this->count(CountKind::HealthPost, "{$verdict->group->value}\t{$path}");
        }

        if ($verdict->group === TrafficGroup::People) {
            $this->person($hit, $status, $path);
        }

        if (in_array($verdict->group, [TrafficGroup::AnswerEngines, TrafficGroup::SearchEngines], true) && in_array($hit->status, [404, 410], true)) {
            $this->count(CountKind::HealthEngineNotFound, $path);
        }

        if ($verdict->group === TrafficGroup::Ours && $verdict->name === Classifier::HEARTBEAT) {
            $this->beats++;

            if ($hit->status >= 200 && $hit->status < 400) {
                $this->answeredBeats[] = $hit->when->getTimestamp();
            }
        }
    }

    /**
     * Put the day's health into its figures: the breakdowns, and the
     * heartbeat between the first instant the logs cover and the day's end
     * (none when no beat came that day).
     */
    public function finish(DayFigures $figures, ?CarbonImmutable $from, CarbonImmutable $until): void
    {
        foreach ($this->counts as $kind => $names) {
            foreach ($names as $name => $count) {
                $figures->count(CountKind::from($kind), (string) $name, $count);
            }
        }

        if ($this->beats === 0 || $from === null) {
            return;
        }

        $start = $from->getTimestamp();
        $end = $until->getTimestamp();
        $expected = max(1, intdiv($end - $start, self::BEAT_SECONDS));
        $slots = [];

        foreach ($this->answeredBeats as $time) {
            $slots[min($expected - 1, intdiv($time - $start, self::BEAT_SECONDS))] = true;
        }

        $gaps = [];
        $previous = $start;

        foreach ([...$this->answeredBeats, $end] as $time) {
            if ($time - $previous > self::GAP_SECONDS) {
                $gaps[] = ['from' => $previous, 'to' => $time, 'minutes' => intdiv($time - $previous, 60)];
            }

            $previous = max($previous, $time);
        }

        $figures->heartbeat = [
            'expected' => $expected,
            'up' => count($slots),
            'beats' => $this->beats,
            'failed' => $this->beats - count($this->answeredBeats),
            'gaps' => $gaps,
        ];
    }

    /**
     * The status class of an answer: 404 apart from the other 4xx.
     */
    public static function statusClass(int $status): string
    {
        return match (true) {
            $status === 404 => '404',
            $status >= 200 && $status < 600 => intdiv($status, 100).'xx',
            default => 'other',
        };
    }

    /**
     * What a status code means, in a few words: nginx's own codes too.
     */
    public static function codeMeaning(int $code): string
    {
        $meaning = match ($code) {
            444 => 'No response (nginx)',
            494 => 'Request header too large (nginx)',
            495 => 'SSL certificate error (nginx)',
            496 => 'SSL certificate required (nginx)',
            497 => 'HTTP request sent to HTTPS port (nginx)',
            499 => 'Client closed request (nginx)',
            default => Response::$statusTexts[$code] ?? null,
        };

        return $meaning === null ? __('Unknown status') : __($meaning);
    }

    /**
     * The answer time of a page view, or "not logged".
     */
    public static function speed(?float $seconds): string
    {
        if ($seconds === null) {
            return self::NOT_LOGGED;
        }

        foreach (self::SPEEDS as $speed => $below) {
            if ($seconds < $below) {
                return $speed;
            }
        }

        return array_key_last(self::SPEEDS);
    }

    private function person(LogHit $hit, string $status, string $path): void
    {
        if ($status === '404') {
            $this->count(CountKind::HealthNotFound, $path);
            $this->count(CountKind::HealthNotFoundFrom, $path."\t".$this->sources->referrerLabel($hit->referrer));
        }

        if (! PageRule::isPage($hit, $this->notPages)) {
            return;
        }

        $speed = self::speed($hit->requestTime);
        $this->count(CountKind::HealthSpeed, $speed);

        if (in_array($speed, self::SLOW, true)) {
            $this->count(CountKind::HealthSlowPage, $path);
        }

        if ($hit->requestTime !== null) {
            $this->count(CountKind::HealthPageTimed, $path);
            $this->count(CountKind::HealthPageMs, $path, (int) round($hit->requestTime * 1000));
        }
    }

    private function count(CountKind $kind, string $name, int $by = 1): void
    {
        $this->counts[$kind->value][$name] = ($this->counts[$kind->value][$name] ?? 0) + $by;
    }
}
