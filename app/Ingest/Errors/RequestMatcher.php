<?php

namespace App\Ingest\Errors;

use App\Ingest\LogHit;
use App\Models\Site;
use App\Models\SiteErrorOccurrence;
use Illuminate\Support\Collection;

/**
 * Finds the request each new occurrence answered in the site's access log,
 * for what its error log does not say (the referrer, the browser, the
 * address, often the page): a request within SECONDS of it, to the same path
 * when the error log named one, otherwise one answered 5xx (or the status the
 * error log named). Only one such request, or one closer than the others, is
 * taken: never a guess between two.
 *
 * The two shippers do not send at the same moment, so an occurrence not
 * found yet is looked for again, until `ingest.error_lookup_minutes` after it.
 */
final readonly class RequestMatcher
{
    /**
     * The most seconds between the error and its request.
     */
    public const int SECONDS = 2;

    /**
     * Occurrences looked for at once, per site.
     */
    private const int BATCH = 500;

    /**
     * Occurrences further apart than this are searched for apart, in seconds.
     */
    private const int CLUSTER = 60;

    public function __construct(private Site $site) {}

    /**
     * Look for the requests of the occurrences still waiting for theirs.
     *
     * @return int the requests found
     */
    public function run(): int
    {
        $pending = $this->site->errorOccurrences()->whereNull('looked_up_at')->orderBy('at')->limit(self::BATCH)->get();
        $search = AccessLogSearch::for($this->site);
        $giveUpBefore = now()->subMinutes((int) config('ingest.error_lookup_minutes'));
        $found = 0;

        foreach ($this->clusters($pending) as $cluster) {
            $hits = $search->between(
                $cluster->first()->at->getTimestamp() - self::SECONDS,
                $cluster->last()->at->getTimestamp() + self::SECONDS,
            );

            foreach ($cluster as $occurrence) {
                $hit = $this->requestOf($occurrence, $hits);

                if ($hit !== null) {
                    $occurrence->update([
                        'method' => substr($hit->method, 0, 10) ?: $occurrence->method,
                        'path' => mb_strcut($hit->url, 0, 2000) ?: $occurrence->path,
                        'host' => $hit->host !== '' ? $hit->host : ($occurrence->host ?? $this->site->host()),
                        'referrer' => $hit->referrer === '' ? null : mb_strcut($hit->referrer, 0, 2000),
                        'user_agent' => $hit->userAgent === '' ? null : mb_strcut($hit->userAgent, 0, 500),
                        'address' => substr($hit->address, 0, 45),
                        'status' => $hit->status,
                        'request_from' => SiteErrorOccurrence::FROM_ACCESS,
                        'looked_up_at' => now(),
                    ]);
                    $found++;
                } elseif ($occurrence->at->lessThan($giveUpBefore)) {
                    $occurrence->update(['looked_up_at' => now()]);
                }
            }
        }

        return $found;
    }

    /**
     * The request the occurrence answered, or null when there is none or more than one could be.
     *
     * @param  list<LogHit>  $hits
     */
    public function requestOf(SiteErrorOccurrence $occurrence, array $hits): ?LogHit
    {
        $at = $occurrence->at->getTimestamp();
        $path = $occurrence->path === null ? null : self::comparablePath($occurrence->path);
        $candidates = [];

        foreach ($hits as $hit) {
            $gap = abs($hit->when->getTimestamp() - $at);

            if ($gap > self::SECONDS) {
                continue;
            }

            $fits = $path !== null
                ? self::comparablePath($hit->path()) === $path && ($occurrence->method === null || $occurrence->method === $hit->method)
                : ($occurrence->status !== null ? $hit->status === $occurrence->status : $hit->status >= 500);

            if ($fits) {
                $candidates[] = [$gap, $hit];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => $a[0] <=> $b[0]);
        [$gap, $best] = $candidates[0];
        $tied = array_filter($candidates, fn (array $candidate) => $candidate[0] === $gap && $candidate[1]->address !== $best->address);

        return $tied === [] ? $best : null;
    }

    /**
     * A path as both logs can write it: no query, no slash at either end.
     */
    private static function comparablePath(string $path): string
    {
        $query = strpos($path, '?');

        return trim($query === false ? $path : substr($path, 0, $query), '/');
    }

    /**
     * The occurrences, in runs close enough in time to be searched for together.
     *
     * @param  Collection<int, SiteErrorOccurrence>  $occurrences
     * @return list<Collection<int, SiteErrorOccurrence>>
     */
    private function clusters(Collection $occurrences): array
    {
        $clusters = [];
        $current = collect();

        foreach ($occurrences as $occurrence) {
            if ($current->isNotEmpty() && $occurrence->at->getTimestamp() - $current->last()->at->getTimestamp() > self::CLUSTER) {
                $clusters[] = $current;
                $current = collect();
            }

            $current->push($occurrence);
        }

        if ($current->isNotEmpty()) {
            $clusters[] = $current;
        }

        return $clusters;
    }
}
