<?php

namespace Tests\Unit;

use App\Enums\LogFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LogParserTest extends TestCase
{
    private const COMBINED = '203.0.113.7 - - [21/Oct/2026:10:42:05 +0200] "GET /pricing/?ref=x HTTP/1.1" 200 5120 "https://www.google.com/" "Mozilla/5.0 (X11; Linux x86_64)"';

    private const EXTENDED = '2026-10-21T10:42:05+00:00 203.0.113.7 www.example.com "GET /blog/ HTTP/2.0" 304 0 "-" "Mozilla/5.0" 0.012 cc=FR rid=0123456789abcdef0123456789abcdef';

    public function test_it_reads_the_combined_format(): void
    {
        $hit = LogFormat::NginxCombined->parser()->parse(self::COMBINED);

        $this->assertNotNull($hit);
        $this->assertSame('203.0.113.7', $hit->address);
        $this->assertSame('2026-10-21T08:42:05+00:00', $hit->when->toIso8601String());
        $this->assertSame('GET', $hit->method);
        $this->assertSame('/pricing/?ref=x', $hit->url);
        $this->assertSame('/pricing/', $hit->path());
        $this->assertSame(200, $hit->status);
        $this->assertSame(5120, $hit->size);
        $this->assertSame('https://www.google.com/', $hit->referrer);
        $this->assertSame('Mozilla/5.0 (X11; Linux x86_64)', $hit->userAgent);
        $this->assertSame('', $hit->host);
        $this->assertSame(LogFormat::NginxCombined, $hit->format);
    }

    public function test_the_time_alone_is_read_the_same_as_with_the_whole_line(): void
    {
        $combined = LogFormat::NginxCombined->parser();
        $extended = LogFormat::NginxExtended->parser();

        $this->assertSame($combined->parse(self::COMBINED)->when->getTimestamp(), $combined->timestamp(self::COMBINED));
        $this->assertSame($extended->parse(self::EXTENDED)->when->getTimestamp(), $extended->timestamp(self::EXTENDED));
        $this->assertNull(LogFormat::NginxExtended->parser()->timestamp(self::COMBINED));
        $this->assertNull(LogFormat::NginxCombined->parser()->timestamp('not a log line'));
    }

    public function test_apache_lines_are_marked_as_apache(): void
    {
        $this->assertSame(LogFormat::ApacheCombined, LogFormat::ApacheCombined->parser()->parse(self::COMBINED)?->format);
    }

    public function test_it_reads_the_optional_fields_after_the_user_agent(): void
    {
        $hit = LogFormat::NginxCombined->parser()->parse(self::COMBINED.' 0.250 rsc_prefetch=1 request_length=2048 "www.example.com:443"');

        $this->assertSame(0.25, $hit->requestTime);
        $this->assertSame('1', $hit->rscPrefetch);
        $this->assertSame(2048, $hit->requestLength);
        $this->assertSame('www.example.com', $hit->host);
    }

    public function test_escaped_quotes_stay_inside_their_field(): void
    {
        $hit = LogFormat::ApacheCombined->parser()->parse(
            '203.0.113.7 - - [21/Oct/2026:10:42:05 +0000] "GET / HTTP/1.1" 200 12 "-" "Bot \"quoted\" 1.0"'
        );

        $this->assertSame('Bot "quoted" 1.0', $hit->userAgent);
        $this->assertSame('', $hit->referrer);
    }

    public function test_a_request_that_is_not_http_is_kept_without_method(): void
    {
        $hit = LogFormat::NginxCombined->parser()->parse('203.0.113.7 - - [21/Oct/2026:10:42:05 +0000] "\x16\x03\x01" 400 157 "-" "-"');

        $this->assertSame(400, $hit->status);
        $this->assertSame('', $hit->method);
        $this->assertSame('', $hit->url);
    }

    public function test_it_reads_the_extended_format(): void
    {
        $hit = LogFormat::NginxExtended->parser()->parse(self::EXTENDED);

        $this->assertSame('www.example.com', $hit->host);
        $this->assertSame(304, $hit->status);
        $this->assertSame('', $hit->referrer);
        $this->assertSame(0.012, $hit->requestTime);
        $this->assertSame('FR', $hit->country);
        $this->assertSame('0123456789abcdef0123456789abcdef', $hit->requestId);
    }

    public function test_unknown_countries_are_left_empty(): void
    {
        $hit = LogFormat::NginxExtended->parser()->parse(str_replace('cc=FR', 'cc=XX', self::EXTENDED));

        $this->assertSame('', $hit->country);
    }

    public function test_auto_reads_both_nginx_formats(): void
    {
        $parser = LogFormat::Auto->parser();

        $this->assertSame(LogFormat::NginxExtended, $parser->parse(self::EXTENDED)?->format);
        $this->assertSame(LogFormat::NginxCombined, $parser->parse(self::COMBINED)?->format);
    }

    public function test_each_format_refuses_the_other(): void
    {
        $this->assertNull(LogFormat::NginxCombined->parser()->parse(self::EXTENDED));
        $this->assertNull(LogFormat::NginxExtended->parser()->parse(self::COMBINED));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableLines(): array
    {
        return [
            'empty' => [''],
            'free text' => ['hello world'],
            'json' => ['{"ts": 1, "request": {"uri": "/"}}'],
            'a bad date' => ['203.0.113.7 - - [99/Foo/2026:10:42:05 +0000] "GET / HTTP/1.1" 200 12 "-" "-"'],
            'a bad status' => ['203.0.113.7 - - [21/Oct/2026:10:42:05 +0000] "GET / HTTP/1.1" OK 12 "-" "-"'],
        ];
    }

    #[DataProvider('unreadableLines')]
    public function test_lines_in_no_format_are_refused(string $line): void
    {
        $this->assertNull(LogFormat::Auto->parser()->parse($line));
    }
}
