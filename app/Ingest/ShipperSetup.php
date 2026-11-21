<?php

namespace App\Ingest;

use App\Enums\ErrorLogKind;
use App\Models\Site;

/**
 * The commands that install edgelog-ship on a site's server (Debian or
 * Ubuntu), filled in with the site's own addresses.
 */
final readonly class ShipperSetup
{
    public function __construct(private Site $site) {}

    /**
     * The address the shipper posts to.
     */
    public function ingestUrl(): string
    {
        return route('ingest.store', $this->site);
    }

    /**
     * Download the shipper and give it a user of its own, in the group that
     * reads the logs, with the folder that keeps its place in them.
     */
    public function installCommands(): string
    {
        $download = route('shipper.download');

        return <<<SH
            curl -fsSL {$download} -o edgelog-ship || wget -qO edgelog-ship {$download}
            sudo install -m 0755 edgelog-ship /usr/local/bin/edgelog-ship
            sudo useradd --system --no-create-home --shell /usr/sbin/nologin --groups adm edgelog-ship
            sudo install -d -m 0700 -o edgelog-ship -g edgelog-ship /var/lib/edgelog-ship
            SH;
    }

    /**
     * Keep the site token in a file only the shipper's user reads.
     */
    public function tokenCommands(): string
    {
        return <<<'SH'
            sudo install -d -m 0750 -o root -g edgelog-ship /etc/edgelog-ship
            sudo sh -c 'umask 027; cat > /etc/edgelog-ship/token'
            sudo chgrp edgelog-ship /etc/edgelog-ship/token
            SH;
    }

    /**
     * The systemd unit that runs the shipper, written in one command.
     */
    public function serviceCommand(): string
    {
        $log = $this->site->log_format->defaultLogPath();

        return <<<SH
            sudo tee /etc/systemd/system/edgelog-ship.service > /dev/null <<'UNIT'
            [Unit]
            Description=Ship the access log to Edgelog
            After=network-online.target
            Wants=network-online.target

            [Service]
            User=edgelog-ship
            ExecStart=/usr/local/bin/edgelog-ship \\
                --url {$this->ingestUrl()} \\
                --token-file /etc/edgelog-ship/token \\
                --log {$log} \\
                --state /var/lib/edgelog-ship/state \\
                --metrics
            Restart=always
            RestartSec=5
            StateDirectory=edgelog-ship
            StateDirectoryMode=0700
            NoNewPrivileges=true
            ProtectSystem=strict
            ProtectHome=true
            PrivateTmp=true
            ReadOnlyPaths=/var/log /etc/edgelog-ship

            [Install]
            WantedBy=multi-user.target
            UNIT
            SH;
    }

    /**
     * The same shipper on one line, for a daemon that a tool such as Laravel
     * Forge runs as root: it hands over to the shipper's own user at once.
     */
    public function daemonCommand(): string
    {
        $log = $this->site->log_format->defaultLogPath();

        return "runuser -u edgelog-ship -- /usr/local/bin/edgelog-ship --url {$this->ingestUrl()} --token-file /etc/edgelog-ship/token --log {$log} --state /var/lib/edgelog-ship/state --metrics";
    }

    /**
     * Start the service, now and at every boot, and follow what it says.
     */
    public function startCommands(): string
    {
        return <<<'SH'
            sudo systemctl daemon-reload && sudo systemctl enable --now edgelog-ship
            journalctl -u edgelog-ship -f
            SH;
    }

    /**
     * Read one sample of the server's figures, as --metrics sends them, to see what is sent.
     */
    public static function printMetricsCommand(): string
    {
        return 'edgelog-ship --print-metrics';
    }

    /**
     * The local status pages of PHP-FPM and nginx that --fpm-status and
     * --nginx-status read: one line in each pool, and an nginx server that
     * only the machine itself reaches.
     */
    public static function statusPagesConfig(): string
    {
        return <<<'CONF'
            # In each PHP-FPM pool, e.g. /etc/php/8.4/fpm/pool.d/www.conf, then reload php8.4-fpm:
            pm.status_path = /php-fpm-status

            # An nginx server on a local address only, e.g. /etc/nginx/sites-enabled/status, then reload nginx:
            server {
                listen 127.0.0.1:8081;

                location /nginx_status {
                    stub_status;
                }

                location /php-fpm-status {
                    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
                    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
                    include fastcgi_params;
                }
            }
            CONF;
    }

    /**
     * Check the status pages answer, then add them to the shipper's service
     * after --metrics, and restart it.
     */
    public static function enableStatusCommands(): string
    {
        $unit = '/etc/systemd/system/edgelog-ship.service';
        $options = '--fpm-status http://127.0.0.1:8081/php-fpm-status --nginx-status http://127.0.0.1:8081/nginx_status';

        return <<<SH
            curl -s 'http://127.0.0.1:8081/php-fpm-status?json' && curl -s http://127.0.0.1:8081/nginx_status
            sudo grep -q -- --fpm-status {$unit} || sudo sed -i 's|--metrics|& {$options}|' {$unit}
            sudo systemctl daemon-reload && sudo systemctl restart edgelog-ship
            SH;
    }

    /**
     * On a server that runs a shipper set up before --metrics: a new
     * shipper, --metrics added to its service, then a restart.
     */
    public function enableMetricsCommands(): string
    {
        $download = route('shipper.download');
        $unit = '/etc/systemd/system/edgelog-ship.service';

        return <<<SH
            curl -fsSL {$download} -o edgelog-ship || wget -qO edgelog-ship {$download}
            sudo install -m 0755 edgelog-ship /usr/local/bin/edgelog-ship
            sudo grep -q -- --metrics {$unit} || sudo sed -i 's|--state /var/lib/edgelog-ship/state|& --metrics|' {$unit}
            sudo systemctl daemon-reload && sudo systemctl restart edgelog-ship
            SH;
    }

    /**
     * Send the web server's past log files once, as the shipper's user, which reads the logs.
     */
    public function backfillCommand(): string
    {
        $log = $this->site->log_format->defaultLogPath();

        return <<<SH
            sudo -u edgelog-ship edgelog-ship \\
                --url {$this->ingestUrl()} \\
                --token-file /etc/edgelog-ship/token \\
                --backfill {$log}*
            SH;
    }

    /**
     * The systemd unit that ships one application error log, and its start:
     * one unit a log, each with its own place in it. A pattern follows the
     * newest file of an app that writes one a day.
     */
    public function errorsServiceCommands(ErrorLogKind $kind): string
    {
        $unit = "edgelog-ship-errors-{$kind->value}";

        return <<<SH
            sudo tee /etc/systemd/system/{$unit}.service > /dev/null <<'UNIT'
            [Unit]
            Description=Ship the {$kind->label()} errors to Edgelog
            After=network-online.target
            Wants=network-online.target

            [Service]
            User=edgelog-ship
            ExecStart=/usr/local/bin/edgelog-ship \\
                --url {$this->ingestUrl()} \\
                --token-file /etc/edgelog-ship/token \\
                --errors {$kind->value} \\
                --log '{$kind->examplePath()}' \\
                --state /var/lib/edgelog-ship/errors-{$kind->value}.state
            Restart=always
            RestartSec=5
            StateDirectory=edgelog-ship
            StateDirectoryMode=0700
            NoNewPrivileges=true
            ProtectSystem=strict
            ProtectHome=read-only
            PrivateTmp=true
            ReadOnlyPaths=/var/log /home /etc/edgelog-ship

            [Install]
            WantedBy=multi-user.target
            UNIT
            sudo systemctl daemon-reload && sudo systemctl enable --now {$unit}
            journalctl -u {$unit} -f
            SH;
    }

    /**
     * Let the shipper's user read the logs of the apps a Forge user runs.
     */
    public static function errorsReadAccessCommand(): string
    {
        return 'sudo usermod -aG forge edgelog-ship';
    }

    /**
     * A tap that writes the request (URL, address, method, host, referrer)
     * after each entry of laravel.log, with Monolog's WebProcessor.
     */
    public static function laravelRequestTap(): string
    {
        return <<<'PHP'
            // app/Logging/AddRequest.php
            namespace App\Logging;

            use Illuminate\Log\Logger;
            use Monolog\Processor\WebProcessor;

            class AddRequest
            {
                public function __invoke(Logger $logger): void
                {
                    $logger->pushProcessor(new WebProcessor);
                }
            }

            // config/logging.php, in the channel that writes laravel.log (single or daily):
            'tap' => [App\Logging\AddRequest::class],
            PHP;
    }

    /**
     * Keep stylesheets and scripts in nginx's log, and only images and fonts out.
     */
    public static function nginxAssetLogging(): string
    {
        return <<<'NGINX'
            location ^~ /assets/ {
                expires 30d;
                try_files $uri =404;

                # Images and fonts may stay out of the log; stylesheets and scripts may not.
                location ~* \.(?:png|jpe?g|gif|webp|avif|svg|ico|ttf|woff2?)$ {
                    access_log off;
                    expires 30d;
                }
            }
            NGINX;
    }
}
