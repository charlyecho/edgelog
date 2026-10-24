<?php

namespace App\Enums;

use App\Ingest\Parsers\AnyOfParser;
use App\Ingest\Parsers\CombinedLogParser;
use App\Ingest\Parsers\ExtendedLogParser;
use App\Ingest\Parsers\LogLineParser;

enum LogFormat: string
{
    case Auto = 'auto';
    case NginxCombined = 'nginx_combined';
    case NginxExtended = 'nginx_extended';
    case ApacheCombined = 'apache_combined';

    /**
     * Get the human readable name of the format.
     */
    public function label(): string
    {
        return match ($this) {
            self::Auto => __('Detect it (nginx or Apache)'),
            self::NginxCombined => __('nginx, combined (the default)'),
            self::NginxExtended => __('nginx, extended (host, time taken, request id)'),
            self::ApacheCombined => __('Apache, combined'),
        };
    }

    /**
     * Get the parser that reads lines written in this format.
     */
    public function parser(): LogLineParser
    {
        return match ($this) {
            self::Auto => new AnyOfParser([new ExtendedLogParser, new CombinedLogParser(self::NginxCombined)]),
            self::NginxExtended => new ExtendedLogParser,
            self::NginxCombined, self::ApacheCombined => new CombinedLogParser($this),
        };
    }

    /**
     * Get the log file the web server writes by default on Debian and Ubuntu.
     */
    public function defaultLogPath(): string
    {
        return $this === self::ApacheCombined ? '/var/log/apache2/access.log' : '/var/log/nginx/access.log';
    }
}
