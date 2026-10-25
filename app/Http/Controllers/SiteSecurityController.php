<?php

namespace App\Http\Controllers;

use App\Analytics\Security\AddressReport;
use App\Analytics\Security\SecurityNames;
use App\Analytics\Security\SecurityPeriod;
use App\Analytics\SitePeriod;
use App\Jobs\CheckStack;
use App\Models\Site;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SiteSecurityController extends Controller
{
    /**
     * The block list's exports: [file extension, content type].
     */
    public const array EXPORTS = [
        'nginx' => ['conf', 'text/plain'],
        'cloudflare' => ['csv', 'text/csv'],
        'plain' => ['txt', 'text/plain'],
    ];

    /**
     * Did the server hand a scanner a real file? Every probe for one answered
     * with a page, set against the host's own harmless fallback page.
     */
    public function exposed(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'exposed', fn () => [
            'show' => in_array($request->query('show'), ['possible', 'unknown'], true) ? $request->query('show') : 'both',
        ]);
    }

    /**
     * What runs the site, and whether each release is the newest of a
     * supported branch. Read once a day from outside: the period does not apply.
     */
    public function stack(Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        return view('sites.security.stack', ['team' => $team, 'site' => $site, 'stack' => $site->stack]);
    }

    /**
     * Read what serves the site again, now.
     */
    public function refreshStack(Team $team, Site $site): RedirectResponse
    {
        Gate::authorize('update', $site);

        CheckStack::dispatchSync($site);

        return back()->with('status', __('Stack checked again.'));
    }

    /**
     * Which addresses probed the site for files and panels it does not have,
     * and what the server answered.
     */
    public function scanners(Request $request, Team $team, Site $site): View
    {
        $category = array_key_exists((string) $request->query('category'), SecurityNames::categoryLabels()) ? $request->query('category') : null;
        $everyone = $request->query('show') === 'all';

        return $this->tab($request, $team, $site, 'scanners', fn (SecurityPeriod $security) => [
            'category' => $category,
            'everyone' => $everyone,
            'rows' => $security->byAddress(fn ($suspect) => ($everyone || $suspect->probes > 0)
                && ($category === null || isset($suspect->categories[$category]))),
        ]);
    }

    /**
     * What they tried beyond looking around: tricks in the address, hammering
     * a login form, or flooding the server.
     */
    public function attacks(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'attacks', fn (SecurityPeriod $security) => [
            'logins' => $security->attacks('login')->limit(200)->get(),
            'bursts' => $security->attacks('burst')->limit(200)->get(),
            'signs' => $security->signs(),
        ]);
    }

    /**
     * Which "browsers" were not people, and what they took.
     */
    public function fake(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'fake', fn (SecurityPeriod $security) => [
            'rows' => $security->byAddress(fn ($suspect) => $suspect->fake_requests > 0),
        ]);
    }

    /**
     * Which addresses and networks to block, and how.
     */
    public function block(Request $request, Team $team, Site $site): View
    {
        return $this->tab($request, $team, $site, 'block', fn (SecurityPeriod $security) => $this->blockList($security));
    }

    /**
     * Everything kept about one address in the period, and its every request
     * the log still holds. As a panel over the tab it was opened from, or a page.
     */
    public function address(Request $request, Team $team, Site $site): View
    {
        Gate::authorize('view', $site);

        $address = trim((string) $request->query('ip'));
        $choice = in_array($request->query('days'), SitePeriod::CHOICES, true) ? $request->query('days') : '30';
        $report = filter_var($address, FILTER_VALIDATE_IP) === false ? null : new AddressReport(new SecurityPeriod(SitePeriod::choose($site, $choice)), $address);

        return view($request->boolean('panel') ? 'sites.security.address-panel' : 'sites.security.address', [
            'team' => $team,
            'site' => $site,
            'choice' => $choice,
            'address' => $address,
            'report' => $report,
        ]);
    }

    /**
     * The block list as nginx deny lines, a Cloudflare IP list, or a plain list: networks first.
     */
    public function export(Request $request, Team $team, Site $site, string $format): Response
    {
        Gate::authorize('view', $site);
        abort_unless(isset(self::EXPORTS[$format]), 404);

        $choice = in_array($request->query('days'), SitePeriod::CHOICES, true) ? $request->query('days') : '30';
        ['networks' => $networks, 'addresses' => $addresses] = $this->blockList(new SecurityPeriod(SitePeriod::choose($site, $choice)));
        $entries = [...array_keys($networks), ...array_column($addresses, 'address')];

        $body = match ($format) {
            'nginx' => implode('', array_map(fn (string $entry) => "deny {$entry};\n", $entries)),
            'cloudflare' => "ip,comment\n".implode('', array_map(fn (string $entry) => "{$entry},edgelog block list\n", $entries)),
            default => implode("\n", $entries)."\n",
        };
        [$extension, $type] = self::EXPORTS[$format];

        return response($body, 200, [
            'Content-Type' => "{$type}; charset=utf-8",
            'Content-Disposition' => 'attachment; filename="'.$site->host()."-block-list.{$extension}\"",
        ]);
    }

    /**
     * The addresses that probed or attacked, and the networks holding two or more of them.
     *
     * @return array{networks: array<string, array{addresses: int, probes: int, attempts: int}>, addresses: list<array<string, mixed>>}
     */
    private function blockList(SecurityPeriod $security): array
    {
        $addresses = $security->byAddress(fn ($suspect) => $suspect->probes > 0 || $suspect->attempts > 0);
        $networks = [];

        foreach ($addresses as $row) {
            $network = SecurityNames::network($row['address']);
            $sum = $networks[$network] ?? ['addresses' => 0, 'probes' => 0, 'attempts' => 0];
            $networks[$network] = ['addresses' => $sum['addresses'] + 1, 'probes' => $sum['probes'] + $row['probes'], 'attempts' => $sum['attempts'] + $row['attempts']];
        }

        $networks = array_filter($networks, fn (array $network) => $network['addresses'] >= 2);
        uasort($networks, fn (array $a, array $b) => [$b['probes'], $b['attempts']] <=> [$a['probes'], $a['attempts']]);

        return ['networks' => $networks, 'addresses' => $addresses];
    }

    /**
     * A tab that reads the period the bar picked.
     *
     * @param  callable(SecurityPeriod): array<string, mixed>  $more
     */
    private function tab(Request $request, Team $team, Site $site, string $tab, callable $more): View
    {
        Gate::authorize('view', $site);

        $choice = in_array($request->query('days'), SitePeriod::CHOICES, true) ? $request->query('days') : '30';
        $security = new SecurityPeriod(SitePeriod::choose($site, $choice));

        return view("sites.security.{$tab}", [
            'team' => $team,
            'site' => $site,
            'choice' => $choice,
            'period' => $security->period,
            'security' => $security,
            ...$more($security),
        ]);
    }
}
