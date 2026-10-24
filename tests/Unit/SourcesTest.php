<?php

namespace Tests\Unit;

use App\Analytics\Sources;
use App\Enums\SourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourcesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, SourceType}>
     */
    public static function sources(): array
    {
        return [
            'a Google search' => ['https://www.google.de/', '/', SourceType::SearchEngine],
            'webmail before search' => ['https://mail.google.com/', '/', SourceType::Email],
            'social media' => ['https://t.co/abc', '/', SourceType::SocialMedia],
            'another website' => ['https://blog.example.org/post', '/', SourceType::OtherWebsite],
            'this site' => ['https://www.example.com/blog/', '/', SourceType::ThisSite],
            'the bare host of this site' => ['https://example.com/', '/', SourceType::ThisSite],
            'a word is not a search engine' => ['https://google-fans.example.org/', '/', SourceType::OtherWebsite],
            'a tagged newsletter' => ['', '/?utm_source=weekly&utm_medium=email', SourceType::Email],
            'the source decides before the medium' => ['', '/?utm_source=linkedin&utm_medium=cpc', SourceType::SocialMedia],
            'a tag naming no type' => ['', '/?utm_source=partner42', SourceType::TaggedLink],
            'an ad click id' => ['', '/?gclid=abc', SourceType::SearchEngine],
            'nothing at all' => ['', '/', SourceType::Direct],
        ];
    }

    #[DataProvider('sources')]
    public function test_a_page_views_source(string $referrer, string $url, SourceType $type): void
    {
        $this->assertSame($type, (new Sources(['www.example.com', 'example.com']))->sourceType($referrer, $url, false));
    }

    public function test_a_citation_is_an_ai_assistant(): void
    {
        $this->assertSame(SourceType::AiAssistant, (new Sources([]))->sourceType('https://google.com/', '/', true));
    }

    public function test_referrer_and_campaign_labels(): void
    {
        $sources = new Sources(['example.com']);

        $this->assertSame('news.ycombinator.com', $sources->referrerLabel('https://news.ycombinator.com/item?id=1'));
        $this->assertSame(Sources::THIS_SITE, $sources->referrerLabel('https://example.com/'));
        $this->assertSame(Sources::NO_REFERRER, $sources->referrerLabel(''));
        $this->assertSame('newsletter / email / october', Sources::campaign('/?utm_source=Newsletter&utm_medium=email&utm_campaign=october'));
        $this->assertSame(Sources::NO_CAMPAIGN, Sources::campaign('/pricing/'));
    }

    public function test_assistants_have_one_name(): void
    {
        $this->assertSame('chatgpt.com', Sources::citedBy('https://chat.openai.com/c/1', '/'));
        $this->assertSame('copilot.microsoft.com', Sources::citedBy('https://www.bing.com/chat?q=x', '/'));
        $this->assertSame('', Sources::citedBy('https://www.bing.com/search?q=x', '/'));
        $this->assertSame('claude.ai', Sources::citedBy('android-app://com.anthropic.claude/', '/'));
    }
}
