<?php

namespace App\Analytics\Classification;

use App\Analytics\OwnPaths;
use App\Analytics\Patterns;
use App\Analytics\Sources;
use App\Enums\EngineTier;
use App\Enums\TrafficGroup;
use App\Ingest\LogHit;

/**
 * Files every request in exactly one of the five groups, in this order
 * (Skald's CLASSIFICATION.md):
 *
 *  0. a probe path or query is noted, not yet decisive (never under one of
 *     the site's own paths: OwnPaths)
 *  3. an assistant fetching for a live question       -> AI assistants, asked
 *  4. an assistant crawling to train or index          -> AI assistants, training
 *  5. a link preview                                   -> bots, "link preview: <app>"
 *     a search crawler                                 -> search engines
 *  6. a probe                                          -> bots, probe
 *  6b to 9b. the whole visit says it is no person      -> bots (VisitEvidence)
 *  7. a self-declared bot, a library, no user agent    -> bots
 * 10. a browser                                        -> people (cited, by the referrer or tag)
 * 11. anything left                                    -> bots
 *
 * (Rules 1 and 2, your own traffic, wait for the site's own lists.)
 */
final readonly class Classifier
{
    public function __construct(private VisitEvidence $evidence, private OwnPaths $ownPaths = new OwnPaths) {}

    public function classify(LogHit $hit): Verdict
    {
        $agent = $hit->userAgent;
        $path = $hit->path();
        $probe = ! $this->ownPaths->matches($path) && (preg_match(Patterns::SECRET_PROBE, $path) === 1
            || preg_match(Patterns::SERVED_PROBE, $path) === 1
            || preg_match(Patterns::PROBE_QUERY, $hit->url) === 1);

        if (preg_match(Patterns::AI_ASKED, $agent, $name) === 1) {
            return new Verdict(TrafficGroup::AnswerEngines, $name[0], EngineTier::Asked, $probe);
        }

        if (preg_match(Patterns::AI_TRAINING, $agent, $name) === 1) {
            return new Verdict(TrafficGroup::AnswerEngines, $name[0], EngineTier::Training, $probe);
        }

        foreach (Patterns::LINK_PREVIEWS as [$pattern, $app]) {
            if (preg_match($pattern, $agent) === 1) {
                return new Verdict(TrafficGroup::Bots, "link preview: {$app}", probe: $probe);
            }
        }

        if (preg_match(Patterns::SEARCH_ENGINE, $agent, $name) === 1) {
            return new Verdict(TrafficGroup::SearchEngines, $name[0], probe: $probe);
        }

        if ($probe) {
            return new Verdict(TrafficGroup::Bots, 'scanner (probe)', probe: true);
        }

        if ($this->evidence->reasonFor($hit) === VisitEvidence::SCANNER) {
            return new Verdict(TrafficGroup::Bots, VisitEvidence::SCANNER);
        }

        if ($agent === '') {
            return new Verdict(TrafficGroup::Bots, 'no user agent');
        }

        if (preg_match(Patterns::OTHER_BOT, $agent) === 1
            || (preg_match(Patterns::COMPATIBLE, $agent) === 1 && preg_match(Patterns::ENGINE, $agent) !== 1)
            || preg_match(Patterns::MACHINE_PATH, $path) === 1) {
            return new Verdict(TrafficGroup::Bots, self::botName($agent));
        }

        if (preg_match(Patterns::BROWSER, $agent) === 1) {
            $reason = $this->evidence->reasonFor($hit);

            if ($reason !== null) {
                return new Verdict(TrafficGroup::Bots, $reason);
            }

            return new Verdict(TrafficGroup::People, citedBy: Sources::citedBy($hit->referrer, $hit->url));
        }

        return new Verdict(TrafficGroup::Bots, self::botName($agent));
    }

    /**
     * A bot's own name: the product it names after "compatible;", else its first product token.
     */
    public static function botName(string $agent): string
    {
        if (preg_match('~compatible;\s*([^;/)]+)~i', $agent, $found) === 1) {
            return substr(trim($found[1]), 0, 60);
        }

        return substr(strtok($agent, '/ ') ?: $agent, 0, 60);
    }
}
