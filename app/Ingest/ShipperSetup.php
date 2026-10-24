<?php

namespace App\Ingest;

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
     * Download the shipper and give it a user of its own, in the group that reads the logs.
     */
    public function installCommands(): string
    {
        $download = route('shipper.download');

        return <<<SH
            curl -fsSL {$download} -o edgelog-ship || wget -qO edgelog-ship {$download}
            sudo install -m 0755 edgelog-ship /usr/local/bin/edgelog-ship
            sudo useradd --system --no-create-home --shell /usr/sbin/nologin --groups adm edgelog-ship
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
                --state /var/lib/edgelog-ship/state
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
     * Start the service, now and at every boot, and follow what it says.
     */
    public function startCommands(): string
    {
        return <<<'SH'
            sudo systemctl daemon-reload && sudo systemctl enable --now edgelog-ship
            journalctl -u edgelog-ship -f
            SH;
    }
}
