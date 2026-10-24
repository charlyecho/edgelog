<?php

namespace Tests\Unit;

use App\Analytics\Classification\Classifier;
use App\Analytics\Classification\VisitEvidence;
use App\Enums\EngineTier;
use App\Enums\LogFormat;
use App\Enums\TrafficGroup;
use App\Ingest\LogHit;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClassifierTest extends TestCase
{
    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private function hit(string $url, string $agent = self::CHROME, int $second = 0, string $referrer = '', int $status = 200, string $address = '203.0.113.7'): LogHit
    {
        return new LogHit(
            when: CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC')->addSeconds($second),
            address: $address,
            host: '',
            method: 'GET',
            url: $url,
            status: $status,
            referrer: $referrer,
            userAgent: $agent,
            format: LogFormat::NginxCombined,
        );
    }

    /**
     * Classify the last hit after the whole day was taken in.
     *
     * @param  list<LogHit>  $day
     */
    private function verdictOf(array $day, ?LogHit $hit = null)
    {
        $evidence = new VisitEvidence(['example.com', 'www.example.com']);

        foreach ($day as $each) {
            $evidence->add($each);
        }

        $evidence->finish();

        return (new Classifier($evidence))->classify($hit ?? $day[array_key_last($day)]);
    }

    /**
     * A page and the stylesheet the browser fetched to draw it.
     *
     * @return list<LogHit>
     */
    private function renderedPage(string $referrer = '', string $url = '/pricing/'): array
    {
        return [
            $this->hit($url, referrer: $referrer),
            $this->hit('/app.css', second: 1, referrer: 'https://example.com/pricing/'),
        ];
    }

    /**
     * @return array<string, array{string, TrafficGroup, ?EngineTier}>
     */
    public static function namedAgents(): array
    {
        return [
            'a live question' => ['Mozilla/5.0 AppleWebKit/537.36; compatible; ChatGPT-User/1.0; +https://openai.com/bot', TrafficGroup::AnswerEngines, EngineTier::Asked],
            'a training crawl' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', TrafficGroup::AnswerEngines, EngineTier::Training],
            'a search crawler' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', TrafficGroup::SearchEngines, null],
            'Applebot indexes' => ['Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17 Safari/605.1.15 (Applebot/0.1)', TrafficGroup::SearchEngines, null],
            'Applebot-Extended trains' => ['Applebot-Extended/0.1', TrafficGroup::AnswerEngines, EngineTier::Training],
            'a library' => ['curl/8.5.0', TrafficGroup::Bots, null],
            'an SEO suite' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', TrafficGroup::Bots, null],
            'compatible with no engine' => ['Mozilla/5.0 (compatible; SomeFetcher)', TrafficGroup::Bots, null],
            'no user agent' => ['', TrafficGroup::Bots, null],
            'an unknown agent' => ['my-script', TrafficGroup::Bots, null],
        ];
    }

    #[DataProvider('namedAgents')]
    public function test_named_agents_land_in_their_group(string $agent, TrafficGroup $group, ?EngineTier $tier): void
    {
        $verdict = $this->verdictOf([$this->hit('/', $agent)]);

        $this->assertSame($group, $verdict->group);
        $this->assertSame($tier, $verdict->tier);
    }

    public function test_a_link_preview_is_named_after_its_app(): void
    {
        $verdict = $this->verdictOf([$this->hit('/', 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)')]);

        $this->assertSame(TrafficGroup::Bots, $verdict->group);
        $this->assertSame('link preview: Slack', $verdict->name);
    }

    public function test_a_browser_that_drew_the_page_is_a_person(): void
    {
        $day = $this->renderedPage();

        $this->assertSame(TrafficGroup::People, $this->verdictOf($day, $day[0])->group);
    }

    public function test_a_browser_that_never_drew_a_page_is_a_bot(): void
    {
        $verdict = $this->verdictOf([$this->hit('/pricing/')]);

        $this->assertSame(TrafficGroup::Bots, $verdict->group);
        $this->assertSame(VisitEvidence::NOT_RENDERED, $verdict->name);
    }

    public function test_a_browser_that_drew_earlier_that_day_stays_a_person(): void
    {
        $later = $this->hit('/blog/', second: 3 * 3600);
        $day = [...$this->renderedPage(), $later];

        $this->assertSame(TrafficGroup::People, $this->verdictOf($day, $later)->group);
    }

    public function test_one_probe_marks_the_whole_visitor(): void
    {
        $page = $this->renderedPage();
        $day = [...$page, $this->hit('/.env', second: 600, status: 404)];

        $this->assertSame(VisitEvidence::SCANNER, $this->verdictOf($day, $page[0])->name);
        $this->assertTrue($this->verdictOf($day)->probe);
    }

    public function test_a_wordpress_path_moves_only_its_own_request(): void
    {
        $page = $this->renderedPage();
        $day = [...$page, $this->hit('/wp-login.php', second: 10, status: 404)];

        $this->assertSame(TrafficGroup::People, $this->verdictOf($day, $page[0])->group);
        $this->assertSame(TrafficGroup::Bots, $this->verdictOf($day)->group);
    }

    public function test_a_page_named_like_a_scanners_word_is_a_page(): void
    {
        $day = $this->renderedPage(url: '/guides/backup-and-restore/');

        $this->assertSame(TrafficGroup::People, $this->verdictOf($day, $day[0])->group);
    }

    public function test_a_browser_visit_that_reads_robots_txt_is_a_crawler(): void
    {
        $day = [$this->hit('/robots.txt'), ...$this->renderedPage(), $this->hit('/blog/', second: 2)];

        $this->assertSame(VisitEvidence::CRAWLER, $this->verdictOf($day, $day[1])->name);
    }

    public function test_six_pages_in_two_seconds_is_too_fast(): void
    {
        $day = [$this->hit('/app.js')];

        foreach (range(1, 6) as $n) {
            $day[] = $this->hit("/page-{$n}/", second: $n % 2);
        }

        $this->assertSame(VisitEvidence::TOO_FAST, $this->verdictOf($day, $day[1])->name);
    }

    public function test_a_person_who_followed_a_citation_is_cited(): void
    {
        $day = $this->renderedPage(referrer: 'https://chatgpt.com/');

        $this->assertSame('chatgpt.com', $this->verdictOf($day, $day[0])->citedBy);
    }

    public function test_a_tagged_link_from_an_assistant_is_cited(): void
    {
        $day = $this->renderedPage(url: '/pricing/?utm_source=perplexity');

        $this->assertSame('perplexity.ai', $this->verdictOf($day, $day[0])->citedBy);
    }

    public function test_a_lookalike_host_is_no_citation(): void
    {
        $day = $this->renderedPage(referrer: 'https://thank-you.com/');

        $this->assertSame('', $this->verdictOf($day, $day[0])->citedBy);
    }

    public function test_bot_names_come_from_the_agent(): void
    {
        $this->assertSame('AhrefsBot', Classifier::botName('Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'));
        $this->assertSame('curl', Classifier::botName('curl/8.5.0'));
    }
}
