<?php

namespace App\Analytics;

/**
 * The name lists every count is made from: the AI assistants, search
 * crawlers, link previews, bots and scanner paths, the files that are not
 * pages, and the hosts of search engines, social media and webmail.
 *
 * They are data, not logic: this is the only thing the main count and the
 * audit's recount share (a recount with another list would be counting
 * something else). They follow Skald's classify.py and dimensions.py; see
 * CLASSIFICATION.md there for why each entry is in its list.
 */
final class Patterns
{
    /**
     * Assistants fetching to train a model or build their own index: nobody waits on them.
     */
    public const string AI_TRAINING = '~GPTBot|ClaudeBot|Claude-SearchBot|CCBot|anthropic-ai|OAI-SearchBot'
        .'|PerplexityBot|meta-externalagent|FacebookBot|Bytespider|Amazonbot|Applebot-Extended'
        .'|cohere-ai|Diffbot|omgili|AI2Bot|Timpibot|ImagesiftBot|YouBot|PanguBot|Kangaroo Bot|DuckAssistBot'
        .'|Amzn-SearchBot|Google-CloudVertexBot|cohere-training-data-crawler~i';

    /**
     * Assistants fetching right now, because a person asked them something.
     */
    public const string AI_ASKED = '~ChatGPT-User|Claude-User|Perplexity-User|meta-externalfetcher'
        .'|Gemini-User|BingPreview|MistralAI-User|Claude-Web|Google-Agent|GoogleAgent-Mariner'
        .'|Google-GeminiNotebook|Google-NotebookLM|Amzn-User~i';

    /**
     * The hosts a citation's referrer comes from (the host or a subdomain of
     * it), most specific first, with the Android apps' package ids.
     *
     * @var list<string>
     */
    public const array AI_REFERRER_HOSTS = [
        'chatgpt.com', 'chat.openai.com', 'openai.com', 'perplexity.ai', 'claude.ai',
        'gemini.google.com', 'copilot.microsoft.com', 'you.com', 'phind.com', 'poe.com', 'mistral.ai',
        'com.openai.chatgpt', 'ai.perplexity.app.android', 'com.anthropic.claude',
        'com.google.android.apps.bard', 'com.microsoft.copilot',
    ];

    /**
     * Bing's assistant shares its host with Bing search; only its path tells.
     *
     * @var array<string, string>
     */
    public const array AI_REFERRER_PATHS = ['bing.com' => '/chat'];

    /**
     * One name per assistant, whichever signal found it.
     *
     * @var array<string, string>
     */
    public const array ASSISTANT_ALIASES = [
        'chat.openai.com' => 'chatgpt.com', 'openai.com' => 'chatgpt.com',
        'chatgpt' => 'chatgpt.com', 'openai' => 'chatgpt.com',
        'perplexity' => 'perplexity.ai', 'claude' => 'claude.ai',
        'gemini' => 'gemini.google.com', 'copilot' => 'copilot.microsoft.com',
        'bing.com/chat' => 'copilot.microsoft.com', 'mistral' => 'mistral.ai',
        'com.openai.chatgpt' => 'chatgpt.com', 'ai.perplexity.app.android' => 'perplexity.ai',
        'com.anthropic.claude' => 'claude.ai', 'com.google.android.apps.bard' => 'gemini.google.com',
        'com.microsoft.copilot' => 'copilot.microsoft.com',
    ];

    /**
     * Assistants that tag the link they give instead of sending a referrer.
     */
    public const string AI_UTM = '~utm_source=(chatgpt|openai|perplexity|claude|gemini|copilot|mistral)~i';

    /**
     * Classic indexing: wanted traffic, never filed under bots.
     */
    public const string SEARCH_ENGINE = '~Googlebot|Google-InspectionTool|GoogleOther|APIs-Google|AdsBot-Google'
        .'|Google-Adwords-Instant|bingbot|Slurp|DuckDuckBot|Baiduspider|YandexBot|Exabot'
        .'|Applebot(?!-Extended)|Sogou(?!MSE|MobileBrowser|Explorer)'
        .'|SeznamBot|Yeti(?=/)|coccocbot|Qwantbot|Qwantify|MojeekBot~i';

    /**
     * An app fetching a page because someone pasted its link: [pattern, app], most specific first.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const array LINK_PREVIEWS = [
        ['~facebookexternalhit.*Twitterbot|Twitterbot.*facebookexternalhit~i', 'iMessage'],
        ['~Slackbot|Slack-ImgProxy~i', 'Slack'],
        ['~SkypeUriPreview|MicrosoftPreview~i', 'Microsoft Teams'],
        ['~WhatsApp~i', 'WhatsApp'],
        ['~LinkedInBot~i', 'LinkedIn'],
        ['~Discordbot~i', 'Discord'],
        ['~TelegramBot~i', 'Telegram'],
        ['~Twitterbot~i', 'X (Twitter)'],
        ['~facebookexternalhit|Facebot~i', 'Facebook'],
        ['~Pinterestbot~i', 'Pinterest'],
        ['~redditbot~i', 'Reddit'],
        ['~Embedly~i', 'Embedly'],
        ['~Iframely~i', 'Iframely'],
        ['~Snap URL Preview~i', 'Snapchat'],
        ['~vkShare~i', 'VK'],
        ['~Bluesky Cardyb|Cardyb/~i', 'Bluesky'],
        ['~Mastodon/~i', 'Mastodon'],
        ['~GoogleMessages/~i', 'Google Messages'],
    ];

    /**
     * Automated, but not an engine: libraries, SEO suites, monitors, testers.
     */
    public const string OTHER_BOT = '~(?<![a-z])bot\b(?![\s\d+]*Build/)|bot/\d|\+https?://'
        .'|crawler|spider|scraper|curl|wget|python-requests|httpx|aiohttp|Go-http'
        .'|java/|okhttp|axios|node-fetch|libwww|Scrapy|HeadlessChrome|PhantomJS'
        .'|AhrefsBot|SemrushBot|MJ12bot|DotBot|BLEXBot|DataForSeoBot|PetalBot'
        .'|UptimeRobot|Pingdom|StatusCake|NewRelic|Site24x7|monitoring|Nagios'
        .'|WhatsApp|TelegramBot|Discordbot|Slackbot|vkShare|Embedly|SkypeUriPreview'
        ."|Let's Encrypt validation"
        .'|Chrome-Lighthouse|PTST[/ ]|GTmetrix|Google-PageRenderer'
        .'|ctwatch|jSystem3-metadata-indexer|Nexus 5X Build/MMB29P'
        .'|Google Page Speed Insights|DareBoost|DebugBear|Ghost Inspector|RuxitSynthetic'
        .'|DatadogSynthetics|Neustar WPM|YottaaMonitor|Siteimprove'
        .'|Google Web Preview|Google Search Console|Google-Ads-Overview'
        .'|Google-Ads-Conversions|Google-Adwords-DisplayAds|Google-HotelAdsVerifier'
        .'|DuplexWeb-Google|GoogleImageProxy|Google Favicon|Google-SearchByImage'
        .'|Google Keyword Suggestion|Google-Publisher-Plugin'
        .'|mShots|shrinktheweb|Site-Shot|wkhtmlto|webkit2png|TinEye'
        .'|deadlinkchecker|BrokenLinkCheck|XML-Sitemaps|Pro-Sitemaps|Nutch|Spinn3r'
        .'|HubSpot|Datanyze'
        .'|zgrab|OpenVAS|ProbelySPDR|Hardenize|Siege/|fasthttp|Zombie\.js~i';

    /**
     * "(compatible; ...)" naming no browser engine is how crawlers write a browser-shaped agent.
     */
    public const string COMPATIBLE = '~\(compatible;~i';

    public const string ENGINE = '~AppleWebKit|Gecko|Trident|Presto|Chrome/|Safari/|Firefox/~i';

    /**
     * Looks like a browser: the weakest claim, checked last.
     */
    public const string BROWSER = '~Mozilla/|Opera/|Safari/|Chrome/|Firefox/~i';

    /**
     * Certificate validation: only machines ask for it.
     */
    public const string MACHINE_PATH = '~^/\.well-known/acme-challenge/~';

    /**
     * Files only a crawler asks for: a browser never fetches them on its own.
     */
    public const string CRAWLER_PATH = '~^/(?:robots\.txt|ads\.txt|app-ads\.txt|security\.txt|\.well-known/security\.txt)$'
        .'|sitemap[^/]*\.(?:xml|txt)(?:\.gz)?$~i';

    /**
     * Secret, config and infrastructure files no site serves to a reader:
     * asking for one marks the whole visitor as a scanner.
     */
    public const string SECRET_PROBE = '~'
        .'(?:^|/)\.(?:env|git|svn|hg|bzr|aws|ssh|docker|vscode|idea|npmrc|htpasswd'
        .'|htaccess|bash_history|ds_store|config|terraform|kube|circleci|travis|gitlab-ci|github)(?:[._/-]|$)'
        .'|(?:^|/)(?:backups?|bak|db|database|dump|site|www|web|html|public_html'
        .'|wwwroot|data|old|test)\.(?:zip|tar|tar\.gz|tgz|gz|rar|7z|sql|bak)$'
        .'|\.(?:sql|sql\.gz|sqlite3?|bak|swp)$|(?:^|/)backups?/?$'
        .'|(?:^|/)id_(?:rsa|dsa|ecdsa|ed25519)(?:\.pub)?$|\.(?:pem|key|p12|pfx)$'
        .'|(?:^|/)(?:credentials?|secrets?|service-account[^/]*)\.(?:json|ya?ml|xml|ini|env|txt)$'
        .'|(?:^|/)docker-compose[^/]*\.ya?ml$'
        .'|\.tf(?:vars)?(?:\.json)?$|\.tfstate(?:\.backup)?$'
        .'|(?:^|/)cdk\.out(?:/|$)|(?:^|/)cdk(?:\.context)?\.json$'
        .'|(?:^|/)(?:appspec|buildspec|serverless|cloudbuild|bitbucket-pipelines'
        .'|azure-pipelines|kustomization|skaffold)\.ya?ml$'
        .'|(?:^|/)(?:samconfig\.toml|kubeconfig(?:\.ya?ml)?|Jenkinsfile|Dockerfile)$'
        .'|^/(?:template|values|Chart)\.ya?ml$'
        .'|(?:^|/)(?:composer\.lock|package-lock\.json|yarn\.lock|pnpm-lock\.yaml'
        .'|Gemfile\.lock|Pipfile\.lock|poetry\.lock|Cargo\.lock)$'
        .'|^/(?:composer\.json|package\.json|Gemfile|Pipfile|requirements\.txt|go\.mod'
        .'|Cargo\.toml|pyproject\.toml)$'
        .'~i';

    /**
     * Probes that move only their own request: real readers ask for some of them too.
     */
    public const string SERVED_PROBE = '~'
        .'\.(?:php\d?|phtml|asp|aspx|jsp|jspa|cgi|pl|cfm)(?:/|$)'
        .'|(?:^|/)(?:wp-admin|wp-login|wp-content|wp-includes|wp-json|wp-config|xmlrpc)(?:[/.]|$)'
        .'|^/+(?:wordpress|wp)(?:[/.]|$)'
        .'|(?:^|/)(?:firebase[^/]*|config|settings|appsettings|parameters|key)\.(?:json|ya?ml|xml|ini|env|txt)$'
        .'|(?:^|/)env\.js$'
        .'|(?:^|/)(?:admin|administrator|phpmyadmin|pma|myadmin|adminer|cpanel'
        .'|cgi-bin|boaform|hudson|jenkins|solr|struts|actuator|telescope|_ignition'
        .'|_debugbar|_profiler|laravel|druid|geoserver|HNAP1|owa|autodiscover'
        .'|vendor/phpunit|containers/json|api/config|sdk)(?:[/.]|$)'
        .'|phpinfo|eval-stdin|invokefunction|(?:^|/)\.well-known/ALFA'
        .'~i';

    /**
     * Attack strings that arrive in the query string instead of the path.
     */
    public const string PROBE_QUERY = '~\.\./|%2e%2e|/etc/passwd|\$\{jndi:|<script|union(?:\s|%20|\+)+select'
        .'|XDEBUG_SESSION|allow_url_include|auto_prepend_file~i';

    /**
     * Files, not pages: by extension or by framework folder.
     */
    public const string ASSET = '~\.(?:css|js|mjs|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|eot|txt|xml|json|webmanifest'
        .'|xsl|xslt|rss|atom|csv|tsv|pdf|zip|gz|tgz|rar|7z|mp4|webm|mov|mp3|wav|ogg|m4a'
        .'|docx?|xlsx?|pptx?|wasm)$|^/_next/|^/static/|^/assets/|^/_nuxt/|^/build/~i';

    /**
     * What a browser fetches when it draws a page: its scripts, its styles, a framework payload.
     */
    public const string RENDER_ASSET = '~\.(?:css|js|mjs)$|^/_next/static/~i';

    /**
     * A React or Next client fetching a page's data, not the page.
     */
    public const string RSC_PAYLOAD = '~[?&](?:_rsc|__flight__|_data)=~i';

    public const string RSC_QUERY = '~[?&]_rsc=~i';

    /**
     * Endpoints a page calls while it is read: evidence of a visit, never a visit of their own.
     */
    public const string BEACON = '~^/(?:api(?:/|$)|(?:__analytics|collect|beacon|ping)\b)~i';

    /**
     * Search engines by their real domains, never by the word.
     */
    public const string SEARCH_HOST = '~(?:^|\.)(?:google\.(?:com(?:\.[a-z]{2})?|co\.[a-z]{2}|[a-z]{2,3})'
        .'|bing\.com|duckduckgo\.com|yahoo\.com|yandex\.[a-z.]+'
        .'|baidu\.com|ecosia\.org|search\.brave\.com|startpage\.com|qwant\.com'
        .'|naver\.com|seznam\.cz|kagi\.com|yep\.com|aol\.com)$'
        .'|^com\.google\.android\.googlequicksearchbox$~';

    public const string SOCIAL_HOST = '~(?:^|\.)(?:facebook\.com|fb\.com|instagram\.com|linkedin\.com|lnkd\.in|t\.co'
        .'|twitter\.com|x\.com|reddit\.com|youtube\.com|youtu\.be|pinterest\.[a-z.]+'
        .'|tiktok\.com|threads\.net|ycombinator\.com|quora\.com|t\.me|whatsapp\.com'
        .'|slack\.com|discord\.com|bsky\.app|mastodon\.social)$'
        .'|^com\.(?:linkedin|facebook|instagram|twitter|reddit|slack)[a-z.]*$'
        .'|^(?:com\.google\.android\.youtube|com\.whatsapp|org\.telegram\.messenger)(?:\.[a-z0-9.]*)?$~';

    public const string EMAIL_HOST = '~^(?:mail\.google\.com|outlook\.(?:live|office|office365)\.com|mail\.yahoo\.com'
        .'|mail\.proton\.me|com\.google\.android\.gm|com\.microsoft\.office\.outlook)$~';

    /**
     * The words of a utm_ tag that name a source type.
     *
     * @var array<string, list<string>>
     */
    public const array UTM_WORDS = [
        'Search engine' => ['google', 'bing', 'duckduckgo', 'yahoo', 'cpc', 'ppc'],
        'Social media' => ['linkedin', 'facebook', 'instagram', 'twitter', 'x', 'reddit', 'youtube', 'tiktok', 'social'],
        'Email' => ['email', 'newsletter', 'mailchimp', 'hubspot', 'gmail'],
    ];

    /**
     * Ad click ids with no utm_ tag, and the source type they say.
     *
     * @var array<string, string>
     */
    public const array CLICK_IDS = [
        'gclid' => 'Search engine', 'gbraid' => 'Search engine', 'wbraid' => 'Search engine',
        'msclkid' => 'Search engine', 'fbclid' => 'Social media', 'li_fat_id' => 'Social media',
        'ttclid' => 'Social media', 'twclid' => 'Social media',
    ];
}
