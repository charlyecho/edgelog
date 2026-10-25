<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use Throwable;

#[Signature('edgelog:geoip-update
    {--only= : country or city, to fetch one file only}')]
#[Description('Download this month\'s DB-IP Lite country and city files, which tell where visitors are')]
class GeoipUpdateCommand extends Command
{
    /**
     * DB-IP's free files, by month: CC BY 4.0, credited wherever a country is shown.
     */
    private const string URL = 'https://download.db-ip.com/free/dbip-%s-lite-%s.mmdb.gz';

    /**
     * Each file is fetched for this month, or last month's while this one is
     * not out yet, checked, and only then put in place of the old one.
     */
    public function handle(): int
    {
        $kinds = $this->option('only') ? [$this->option('only')] : ['country', 'city'];
        $failed = false;

        foreach ($kinds as $kind) {
            $target = config("analytics.geoip.{$kind}");

            if (! is_string($target) || $target === '') {
                $this->components->error("No file is set for {$kind} (analytics.geoip.{$kind}).");
                $failed = true;

                continue;
            }

            $month = $this->fetch($kind, $target);

            if ($month === null) {
                $failed = true;

                continue;
            }

            $this->components->info("The {$kind} file of {$month} is in place: {$target}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The month of the file put in place, or null when none could be.
     */
    private function fetch(string $kind, string $target): ?string
    {
        File::ensureDirectoryExists(dirname($target));
        $download = "{$target}.download.gz";
        $unpacked = "{$target}.download";

        try {
            foreach ([now()->format('Y-m'), now()->subMonthNoOverflow()->format('Y-m')] as $month) {
                $response = Http::timeout(600)->sink($download)->get(sprintf(self::URL, $kind, $month));

                if ($response->successful()) {
                    $this->unpack($download, $unpacked);
                    (new Reader($unpacked))->close();
                    File::move($unpacked, $target);

                    return $month;
                }
            }

            $this->components->error("DB-IP has no {$kind} file for this month or the last.");
        } catch (Throwable $exception) {
            $this->components->error("The {$kind} file could not be fetched: {$exception->getMessage()}");
        } finally {
            File::delete([$download, $unpacked]);
        }

        return null;
    }

    private function unpack(string $gzip, string $target): void
    {
        $in = gzopen($gzip, 'rb');
        $out = fopen($target, 'wb');

        try {
            while (! gzeof($in)) {
                fwrite($out, gzread($in, 1 << 20));
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
    }
}
