<p align="center">
  <img src="public/logo.svg" alt="Edgelog: a hedgehog with glasses reading GET 200" width="120" height="120">
</p>

<h1 align="center">Edgelog</h1>

<p align="center">
  <strong>Web analytics read from your server's own access log.</strong><br>
  The people, the AI assistants and the crawlers that a JavaScript tracker never sees.<br>
  No cookie, no script in the visitor's browser.
</p>

---

## What it is

Edgelog counts a website's visits from the access log of its web server (nginx or Apache).
More and more requests never run JavaScript (AI assistants fetching a page to answer someone,
crawlers training a model, bots and scanners), so a script-based tool never counts them. The
access log sees every request.

Edgelog is a rewrite in Laravel and SQLite of [Skald](https://github.com/spcaeo/skald), and
follows its features, its behaviour and its look.

### What works today

- **Accounts and teams.** Sign up, sign in, forgotten passwords, two-step sign-in (TOTP with
  recovery codes). A sign-up creates a team its author owns; others join by invitation (a link
  mailed, valid 7 days, used once, only its hash stored).
- **Roles** per team: `viewer` reads everything; `admin` also invites, removes and changes
  members (not owners) and manages sites; `owner` also manages owners and deletes the team. A
  team always keeps one owner.
- **Sites.** Each site belongs to exactly one team and can be moved to another team its admin
  manages. Every site has a UUID (its URLs are `/teams/{team uuid}/sites/{site uuid}`) and a
  token, shown once and stored as a hash only.
- **Log ingestion.** `edgelog-ship`, one shell script to install on the site's server, tails the
  access log and posts its new lines to `POST /ingest/{site uuid}` with the site token. Every
  line is kept once, and none is lost (see [Sending a site's log](#sending-a-sites-log)).
- **The daily run.** Each site's day, cut at midnight in the site's time zone, is counted from its
  log, checked, then counted a second time by an independent audit. Only a day both counts agree
  on is shown; one that does not add up is withheld, never guessed (see
  [The daily run](#the-daily-run)).
- **Overview** of a site. *Summary*: visitors, pages read, visitors from AI, AI assistants,
  search engines and bots, each with its change and its days, a daily chart, the top countries,
  pages, sources and AI assistants, who else came, and each day in numbers, for yesterday, 7, 30,
  90 days or all time. *Real time*: today so far, refreshed every two minutes.
- **Translatable interface**: every text goes through `__()` with English keys. English only
  for now.

## Requirements

- PHP 8.3 or newer, with the `sqlite3`, `zlib` and `mbstring` extensions
- Composer 2
- Node.js 20 or newer (to build the front end with Vite)
- On each site's server, nothing to install: the shipper is a POSIX shell script that uses
  `gzip` and `curl` or GNU `wget`, all on a fresh Debian or Ubuntu

## Installation

```sh
git clone <this repository> edgelog
cd edgelog
composer setup
```

`composer setup` installs the PHP and JavaScript packages, creates `.env` from `.env.example`,
generates the application key, creates the SQLite database (`database/database.sqlite`), runs
the migrations and builds the front end.

Then set the address the application is served from in `.env` (it is used in the links of
the mails and in the shipper's commands):

```dotenv
APP_URL=https://edgelog.example.com
```

For a local test account (`test@example.com`, password `password`) with a team and two sites:

```sh
php artisan db:seed
```

### Running it

In development, `composer dev` starts the development processes (the PHP server and Vite among
them) together. Or serve `public/` with any PHP web server (nginx and PHP-FPM, Laravel Herd, Valet)
and run `npm run dev` for the front end.

In production:

```sh
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
```

The daily run needs the scheduler and a queue worker. With cron:

```cron
* * * * * cd /path/to/edgelog && php artisan schedule:run >> /dev/null 2>&1
```

and a worker kept running (systemd, Supervisor):

```sh
php artisan queue:work --timeout=1800
```

In development, `php artisan schedule:work` and `php artisan queue:work` do the same in a
terminal.

### Mail

Invitations and password resets are sent by mail. Set the `MAIL_*` variables in `.env` (SMTP,
or any mailer Laravel supports). With the default `MAIL_MAILER=log`, mails are written to
`storage/logs/laravel.log`, and the team page shows each invitation link once so it can be passed
on by hand.

## Sending a site's log

1. Add the site in its team (**Add a site**). Its token is shown once: copy it.
2. Open the site's **Site settings**. **Send the log** gives the commands for the site's server
   (Debian or Ubuntu), filled in with its own address: install `edgelog-ship`, store the token
   in a file only the shipper reads, run it as a systemd service.
3. **Connection** on the same page says what arrived: the last batch, today's lines, and the
   last refusal in plain words.

The formats read are nginx's default `combined`, Apache's `combined`, and an extended nginx
format with the host, the time taken, the country and a request id (the settings page shows its
`log_format`). **Detect it** takes any of them. Lines in another format are counted as refused
and dropped; their content is never kept.

### How no line is lost or counted twice

- The shipper saves its place in the log only after the server answered. Until then it sends
  the batch again, waiting longer each time, and follows the server's `Retry-After`.
- Every batch is numbered (`X-Edgelog-Batch: <shipper id>:<seq>`); a batch the server kept
  before is answered as a duplicate and not written again.
- The server journals each append: a crash halfway is repaired at the next batch.
- Log rotation is followed on both sides. The server keeps one file per site and per UTC day
  under `storage/app/private/ingest/<site uuid>/` (`access.log`, `access.log.1`,
  `access.log.2.gz`, ...).

### Limits

Set in `.env` (see `config/ingest.php`):

| Variable | Default | Meaning |
| --- | --- | --- |
| `INGEST_ROOT` | `storage/app/private/ingest` | where shipped logs are kept |
| `INGEST_PER_MINUTE` | 120 | batches a minute per site |
| `INGEST_KEEP` | 14 | days of rotated files kept per site |
| `INGEST_MAX_MB` | 1024 | the most one site's file may hold in a day |
| `INGEST_TEAM_DAILY_MB` | 200 | the most a team's sites may ship a UTC day, together |
| `INGEST_MIN_FREE_GB` | 5 | below this much free disk, every batch waits |

A batch is at most 5 MiB, packed and unpacked, and a line at most 64 KiB.

## The daily run

Every hour, `edgelog:run-daily` looks for sites whose yesterday is over where the site is, plus
`ANALYTICS_SETTLE_MINUTES` (30) for the shippers' last lines, and not counted yet. For each one it
queues two jobs, chained:

1. **CollectSiteDay** reads the day's lines (every file of the site that may hold them, in time
   order) twice. The first pass learns what only a whole visit tells; the second files every
   request in exactly one of five groups (people, AI assistants, search engines, bots and
   scanners, your own traffic) and adds the day up: requests, page views, visits and readers per
   group, and the breakdowns. Then it checks the arithmetic: the groups add up to the requests,
   every breakdown to the figure it breaks down, at most 1% of the lines unreadable. A day that
   fails is **withheld**.
2. **AuditSiteDay** counts the day again by another road: its own line reader, its own rule
   order, its own page and visit rules, sharing only the lists of names (`app/Analytics/Patterns.php`).
   When both agree the day is **verified**; when they differ it is **withheld**, with the figures
   that differ.

The rules follow Skald's
[CLASSIFICATION.md](https://github.com/spcaeo/skald/blob/main/CLASSIFICATION.md): named AI
assistants (training or answering a live question) and search crawlers by their user agent; link
previews, libraries and self-declared bots as bots; one request for a secret or config file
marks the whole visitor as a scanner; a browser-like visit that fetches `robots.txt` or a
sitemap, that never loads a script, style, framework payload or file of the site's own (asked
for from its pages) near its pages, or that reads 6 pages in 2 seconds, is a bot. A person who
followed an AI assistant's link (by the referrer or a `utm_source` tag) is a visitor from AI. A
page view is a 2xx or 304 answer to a GET or HEAD for a page, never a file, a redirect or a
framework's data fetch. A visit is one address and user agent with no gap over 30 minutes; a page
from a new outside source starts a new one.

A day before the site's first line is not recorded, never zero; a day the logs begin in is
marked as partial.

**Real time.** A `RefreshLiveSnapshot` counts today's log so far with the same rules and keeps it
in the cache for 3 minutes (never in the history). One is queued as soon as a batch brings new
lines (at most once a minute per site), and every two minutes by `edgelog:live` for each site that
sent lines in the last two hours, so "active now" goes down when nothing arrives. Without a worker,
the page counts the snapshot itself once it is older than 3 minutes. The site's **Real time** tab shows it: visitors active now and in
the last 30 minutes, visitors today, minute-by-minute charts, pages being read, the newest visits,
and the AI assistants and crawlers fetching right now. The page reloads itself every two minutes
unless paused.

To count days again (after a change of rules, or a withheld day once its cause is found):

```sh
php artisan edgelog:collect <site uuid> --day=2026-10-21 --days=7 [--sync]
```

Not done yet, compared with Skald: checking a crawler's claim against its owner's published
addresses, your own traffic (lists of addresses and agents), countries from a GeoIP file,
returning visitors, the "likely sent by an AI assistant" estimate, the world map, the Insights
tab and the daily mail.

## Signed-in users

When the site's app knows who is signed in, it can name them in the log (**Site settings >
Signed-in users** has the code):

1. The app sends a pseudonymous id of the user in the `X-Edgelog-User` response header: a keyed
   hash such as `hash_hmac('sha256', $userId, $appSecret)`, never the raw id or an email.
2. The web server writes it at the end of the line, ` uid=$upstream_http_x_edgelog_user` in
   nginx's `log_format` (Apache: ` uid=%{X-Edgelog-User}o`), and takes the header off the response
   (`fastcgi_hide_header` / `proxy_hide_header`; Apache: `Header always unset`), so the browser
   never sees it.

Edgelog then counts, each day, the distinct signed-in users and the visitors who were signed in
(a visit that signs in on its second page counts whole), both recounted by the audit. A user is
counted once over a period, however many days they came. Each user's first visit is kept, with
where it came from, for crediting their payments later: pass the same id to Stripe as the Checkout
Session's `client_reference_id` (or `metadata.uid`, and the subscription's `metadata.uid`), and
the payment keeps it. Ids are kept `ANALYTICS_USERS_KEPT_DAYS` (400) days; a day's counts stay.
A pseudonymous id is still personal data: say so in the site's privacy notice.

## Revenue from Stripe

Each site can count its revenue from Stripe's signed webhooks (**Site settings > Revenue from
Stripe**):

1. In Stripe, **Developers > Webhooks > Add endpoint**, with the site's address
   `https://edgelog.example.com/hooks/<site uuid>/stripe` and the events
   `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `invoice.paid`,
   `refund.created` and `refund.updated`.
2. Paste the endpoint's signing secret (`whsec_...`) in the site settings. It is stored encrypted.

What is counted:

- A one-time payment, from its Checkout Session (mode `payment`) once paid, at once or later by a
  delayed method. A subscription's payments, the first one included, from `invoice.paid` (its
  Checkout Session is not counted, or the first payment would count twice). A refund once it
  succeeded, taken off.
- Live payments only: test-mode events are received and listed in the settings, never counted.
- By day and by currency, never added across currencies. A payment belongs to the day of its first
  event; one Stripe delivers late (it retries for three days) counts its day again.
- The signature (`Stripe-Signature`, 5 minutes of tolerance) is checked on the raw body before
  anything else; each event is kept once. Only the fields the counts read are kept: never the
  customer's name, email or address. The audit recounts the day's revenue from the kept events
  by its own code.

Revenue is not linked to visits yet: the site's figures and its revenue sit side by side. For
defence in depth, allow only [Stripe's addresses](https://docs.stripe.com/ips.md) on
`/hooks/*/stripe` in nginx.

## Tests

```sh
php artisan test
```

## Credits

Edgelog follows [Skald](https://github.com/spcaeo/skald) by spcaeo, licensed under the Elastic
License 2.0. The code here is written anew for Laravel; Skald's documentation is the reference
for how things should behave.
