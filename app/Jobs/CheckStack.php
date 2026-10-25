<?php

namespace App\Jobs;

use App\Analytics\SiteHosts;
use App\Analytics\Stack\EndOfLife;
use App\Analytics\Stack\StackDetector;
use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Read what serves the site from outside, once a day: its home page through
 * its redirects (public addresses only, no cookie, no credentials, a time
 * limit), and the hosts it loads WordPress files from. On a WordPress host,
 * /wp-login.php is asked once, without following it: a 2xx answer is a
 * login page open to the internet. Each version is then set against its
 * branch on endoflife.date.
 */
class CheckStack implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int MAX_REDIRECTS = 3;

    public const int MAX_ORIGINS = 3;

    public function __construct(public Site $site) {}

    public function uniqueId(): string
    {
        return (string) $this->site->id;
    }

    public function handle(EndOfLife $endOfLife): void
    {
        $own = SiteHosts::of($this->site)->hosts;
        $siteHost = strtolower((string) parse_url($this->site->url, PHP_URL_HOST));
        $hosts = [['host' => $siteHost, 'role' => 'site']];
        $checked = [];

        while ($hosts !== [] && count($checked) < 1 + self::MAX_ORIGINS) {
            ['host' => $host, 'role' => $role] = array_shift($hosts);
            $row = ['host' => $host, 'role' => $role, 'status' => null, 'products' => [], 'login' => null, 'error' => null];

            try {
                [$responses, $body] = $this->home($host);
                $row['status'] = end($responses)->status();

                foreach ($responses as $response) {
                    foreach (StackDetector::detect($response) as $product => $found) {
                        if (! isset($row['products'][$product]) || ($row['products'][$product]['version'] === null && $found['version'] !== null)) {
                            $row['products'][$product] = $found;
                        }
                    }
                }

                if (isset($row['products']['wordpress'])) {
                    $row['login'] = $this->wordpressLogin($host);
                }

                if ($role === 'site' && preg_match_all('~https?://([a-z0-9.-]+)/wp-(?:content|includes)/~i', $body, $origins) > 0) {
                    foreach (array_unique(array_map('strtolower', $origins[1])) as $origin) {
                        if (! in_array($origin, $own, true) && count($hosts) < self::MAX_ORIGINS) {
                            $hosts[] = ['host' => $origin, 'role' => 'origin'];
                        }
                    }
                }
            } catch (Throwable $exception) {
                $row['error'] = substr($exception->getMessage(), 0, 250);
            }

            foreach ($row['products'] as $product => $found) {
                $row['products'][$product] = isset(EndOfLife::PRODUCTS[$product]) || isset(StackDetector::COMMANDS[$product])
                    ? [...$found, ...$endOfLife->judge($product, $found['version'])]
                    : [...$found, 'status' => 'shown', 'cycle' => null, 'latest' => null, 'eol' => null, 'newest' => null];
            }

            $checked[] = $row;
        }

        $this->site->stack()->updateOrCreate([], ['hosts' => $checked, 'checked_at' => now()]);
    }

    /**
     * The host's home page through its redirects, each answer kept, and the last one's body.
     *
     * @return array{0: list<Response>, 1: string}
     */
    private function home(string $host): array
    {
        $url = "https://{$host}/";
        $responses = [];

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (! FetchRobotsTxt::isPublic((string) parse_url($url, PHP_URL_HOST))) {
                throw new RuntimeException(__('The site\'s address is not a public one.'));
            }

            $response = $this->client()->get($url);
            $responses[] = $response;
            $location = $response->header('Location');

            if (! $response->redirect() || $location === '') {
                return [$responses, $response->body()];
            }

            $url = str_starts_with($location, 'http') ? $location : "https://{$host}/".ltrim($location, '/');
        }

        return [$responses, ''];
    }

    /**
     * Whether /wp-login.php answers anyone ("open"), refuses ("closed"), or is not there ("none").
     */
    private function wordpressLogin(string $host): string
    {
        $status = $this->client()->head("https://{$host}/wp-login.php")->status();

        return match (true) {
            $status >= 200 && $status < 300 => 'open',
            in_array($status, [401, 403], true) => 'closed',
            default => 'none',
        };
    }

    private function client(): PendingRequest
    {
        return Http::timeout(15)->connectTimeout(5)->withoutRedirecting()
            ->withUserAgent('Mozilla/5.0 (compatible; Edgelog/1.0; stack check)');
    }
}
