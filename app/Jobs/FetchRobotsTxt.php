<?php

namespace App\Jobs;

use App\Models\Site;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read a site's robots.txt from outside, as a crawler would: no cookie, no
 * credentials, a public address only, a size and a time limit.
 */
class FetchRobotsTxt implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The most of a robots.txt that is kept (Google reads 500 KiB).
     */
    public const int MAX_BYTES = 512 * 1024;

    public function __construct(public Site $site) {}

    public function uniqueId(): string
    {
        return (string) $this->site->id;
    }

    public function handle(): void
    {
        $url = rtrim($this->site->url, '/').'/robots.txt';
        $found = ['url' => $url, 'status' => null, 'body' => null, 'error' => null, 'fetched_at' => now()];

        try {
            if (! self::isPublic((string) parse_url($url, PHP_URL_HOST))) {
                throw new \RuntimeException(__('The site\'s address is not a public one.'));
            }

            $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()
                ->withUserAgent('Mozilla/5.0 (compatible; Edgelog/1.0; robots.txt check)')
                ->get($url);

            $found['status'] = $response->status();
            $found['body'] = $response->successful() ? substr($response->body(), 0, self::MAX_BYTES) : null;
        } catch (Throwable $exception) {
            $found['error'] = substr($exception->getMessage(), 0, 250);
        }

        $this->site->robots()->updateOrCreate([], $found);
    }

    /**
     * Determine if every address the host resolves to is a public one.
     */
    public static function isPublic(string $host): bool
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : array_merge(gethostbynamel($host) ?: [], array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));

        return $addresses !== [] && array_filter($addresses, fn (string $address) => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) === [];
    }
}
