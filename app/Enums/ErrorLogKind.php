<?php

namespace App\Enums;

use App\Ingest\Errors\CodeIgniterErrorParser;
use App\Ingest\Errors\CraftErrorParser;
use App\Ingest\Errors\ErrorLogParser;
use App\Ingest\Errors\LaravelErrorParser;
use App\Ingest\Errors\PhpErrorParser;

/**
 * The application logs edgelog-ship --errors <kind> sends, each in its own format.
 */
enum ErrorLogKind: string
{
    case Laravel = 'laravel';
    case CodeIgniter = 'codeigniter';
    case Craft = 'craft';
    case Php = 'php';

    /**
     * Get the human readable name of the log.
     */
    public function label(): string
    {
        return match ($this) {
            self::Laravel => __('Laravel'),
            self::CodeIgniter => __('CodeIgniter 4'),
            self::Craft => __('Craft CMS'),
            self::Php => __('PHP error log'),
        };
    }

    /**
     * Get the parser that reads the entries of this log.
     */
    public function parser(): ErrorLogParser
    {
        return match ($this) {
            self::Laravel => new LaravelErrorParser,
            self::CodeIgniter => new CodeIgniterErrorParser,
            self::Craft => new CraftErrorParser,
            self::Php => new PhpErrorParser,
        };
    }

    /**
     * Get where the log usually is, for the setup commands: a pattern when the
     * application writes a file a day.
     */
    public function examplePath(): string
    {
        return match ($this) {
            self::Laravel => '/home/forge/example.com/storage/logs/laravel.log',
            self::CodeIgniter => '/home/forge/example.com/writable/logs/log-*.log',
            self::Craft => '/home/forge/example.com/storage/logs/web-*.log',
            self::Php => '/var/log/php_errors.log',
        };
    }
}
