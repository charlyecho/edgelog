<?php

namespace Tests\Unit;

use App\Enums\ErrorLogKind;
use App\Ingest\Errors\CodeIgniterErrorParser;
use App\Ingest\Errors\CraftErrorParser;
use App\Ingest\Errors\ErrorEntries;
use App\Ingest\Errors\ErrorFingerprint;
use App\Ingest\Errors\LaravelErrorParser;
use App\Ingest\Errors\PhpErrorParser;
use PHPUnit\Framework\TestCase;

class ErrorLogParsersTest extends TestCase
{
    private const string LARAVEL = <<<'LOG'
        [2026-10-25 14:57:19] production.ERROR: SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'content' at row 1 (Connection: mysql, SQL: insert into `items` values (58003)) {"exception":"[object] (Illuminate\\Database\\QueryException(code: 22001): SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'content' at row 1 (Connection: mysql, SQL: insert into `items` values (58003)) at /home/forge/example.com/vendor/laravel/framework/src/Illuminate/Database/Connection.php:838)
        [stacktrace]
        #0 /home/forge/example.com/vendor/laravel/framework/src/Illuminate/Database/Connection.php(794): Illuminate\\Database\\Connection->runQueryCallback()
        #1 /home/forge/example.com/app/Jobs/SaveImage.php(42): Illuminate\\Database\\Connection->run()
        #2 {main}
        "} {"url":"/feed/refresh?page=2","ip":"203.0.113.7","http_method":"POST","server":"example.com","referrer":"https://example.com/feed"}
        LOG;

    private const string CODEIGNITER = <<<'LOG'
        CRITICAL - 2026-10-29 07:39:23 --> CodeIgniter\Security\Exceptions\SecurityException: The action you requested is not allowed.
        [Method: POST, Route: user/project/autosave]
        in SYSTEMPATH/Security/Security.php on line 258.
         1 SYSTEMPATH/Security/Security.php(258): CodeIgniter\Security\Exceptions\SecurityException::forDisallowedAction()
         2 SYSTEMPATH/Filters/CSRF.php(52): CodeIgniter\Security\Security->verify()
        LOG;

    public function test_a_laravel_exception_is_read_with_its_stack_and_request(): void
    {
        $entry = (new LaravelErrorParser)->parse(self::LARAVEL);

        $this->assertSame('2026-10-25 14:57:19', $entry->at->toDateTimeString());
        $this->assertSame('error', $entry->level);
        $this->assertSame('Illuminate\Database\QueryException', $entry->class);
        $this->assertStringStartsWith('SQLSTATE[22001]: String data, right truncated', $entry->message);
        $this->assertSame('/home/forge/example.com/vendor/laravel/framework/src/Illuminate/Database/Connection.php', $entry->file);
        $this->assertSame(838, $entry->line);
        $this->assertSame(3, substr_count($entry->stack, "\n") + 1);
        $this->assertStringContainsString('#1 /home/forge/example.com/app/Jobs/SaveImage.php(42): Illuminate\Database\Connection->run()', $entry->stack);
        $this->assertSame(['POST', '/feed/refresh?page=2', 'example.com', 'https://example.com/feed', '203.0.113.7'], [$entry->method, $entry->path, $entry->host, $entry->referrer, $entry->address]);
    }

    public function test_a_laravel_message_without_exception_drops_its_context(): void
    {
        $entry = (new LaravelErrorParser)->parse('[2026-10-25 04:37:20] production.ERROR: Payment failed {"order":12} ');

        $this->assertNull($entry->class);
        $this->assertSame('Payment failed', $entry->message);
        $this->assertNull($entry->path);
    }

    public function test_a_context_of_the_apps_own_is_not_taken_for_the_request(): void
    {
        $entry = (new LaravelErrorParser)->parse('[2026-10-25 04:37:20] production.ERROR: Download failed {"url":"https://cdn.example.net/a.jpg"} ');

        $this->assertNull($entry->path);
    }

    public function test_warnings_and_notices_are_left_out(): void
    {
        $this->assertNull((new LaravelErrorParser)->parse('[2026-10-25 04:37:20] production.WARNING: saveImage failed {"url":"x"} '));
        $this->assertNull((new CodeIgniterErrorParser)->parse('INFO - 2026-10-29 07:39:23 --> Controller method is not found'));
        $this->assertNull((new CraftErrorParser)->parse('2026-10-28 18:36:18 [web.WARNING] [application] Slow query'));
        $this->assertNull((new PhpErrorParser)->parse('[29-Oct-2026 10:02:11 UTC] PHP Warning:  Undefined variable $a in /var/www/index.php on line 3'));
        $this->assertNull((new PhpErrorParser)->parse('[29-Oct-2026 10:02:11] NOTICE: [pool www] child 123 started'));
    }

    public function test_a_codeigniter_exception_names_its_route(): void
    {
        $entry = (new CodeIgniterErrorParser)->parse(self::CODEIGNITER);

        $this->assertSame('critical', $entry->level);
        $this->assertSame('CodeIgniter\Security\Exceptions\SecurityException', $entry->class);
        $this->assertSame('The action you requested is not allowed.', $entry->message);
        $this->assertSame(['SYSTEMPATH/Security/Security.php', 258], [$entry->file, $entry->line]);
        $this->assertSame(['POST', '/user/project/autosave'], [$entry->method, $entry->path]);
        $this->assertSame(2, substr_count($entry->stack, "\n") + 1);
    }

    public function test_a_codeigniter_error_line_names_its_file(): void
    {
        $entry = (new CodeIgniterErrorParser)->parse(<<<'LOG'
            ERROR - 2026-10-15 08:23:07 --> Error connecting to the database: mysqli_sql_exception: No such file or directory in /home/forge/example.com/vendor/codeigniter4/framework/system/Database/MySQLi/Connection.php:201
            Stack trace:
            #0 /home/forge/example.com/vendor/codeigniter4/framework/system/Database/MySQLi/Connection.php(201): mysqli->real_connect()
            LOG);

        $this->assertNull($entry->class);
        $this->assertSame('Error connecting to the database: mysqli_sql_exception: No such file or directory', $entry->message);
        $this->assertSame(201, $entry->line);
        $this->assertStringStartsWith('#0 ', $entry->stack);
    }

    public function test_a_craft_exception_gives_its_http_status(): void
    {
        $entry = (new CraftErrorParser)->parse(<<<'LOG'
            2026-10-28 18:36:18 [web.ERROR] [yii\web\HttpException:403] User is not authorized to perform this action. {"trace":["#0 /home/forge/example.com/vendor/craftcms/cms/src/controllers/AssetsController.php(1223): craft\\web\\Controller->requirePermission()","#1 {main}"],"memory":2099928,"exception":"[object] (yii\\web\\ForbiddenHttpException(code: 0): User is not authorized to perform this action. at /home/forge/example.com/vendor/craftcms/cms/src/web/Controller.php:521)"}
            LOG);

        $this->assertSame('yii\web\ForbiddenHttpException', $entry->class);
        $this->assertSame('User is not authorized to perform this action.', $entry->message);
        $this->assertSame(521, $entry->line);
        $this->assertSame(403, $entry->status);
        $this->assertSame("#0 /home/forge/example.com/vendor/craftcms/cms/src/controllers/AssetsController.php(1223): craft\\web\\Controller->requirePermission()\n#1 {main}", $entry->stack);
    }

    public function test_a_php_fatal_error_is_read_in_its_own_time_zone(): void
    {
        $entry = (new PhpErrorParser)->parse(<<<'LOG'
            [29-Oct-2026 03:02:11 America/Los_Angeles] PHP Fatal error:  Uncaught Error: Call to undefined function foo() in /var/www/example.com/index.php:12
            Stack trace:
            #0 {main}
              thrown in /var/www/example.com/index.php on line 12
            LOG);

        $this->assertSame('2026-10-29 10:02:11', $entry->at->toDateTimeString());
        $this->assertSame('critical', $entry->level);
        $this->assertSame(['Error', 'Call to undefined function foo()', 12], [$entry->class, $entry->message, $entry->line]);
        $this->assertSame('#0 {main}', $entry->stack);
    }

    public function test_the_php_log_keeps_the_error_an_app_was_handling(): void
    {
        $entry = (new PhpErrorParser)->parse(<<<'LOG'
            [12-Apr-2025 11:56:13 UTC] An Error occurred while handling another error:
            yii\web\HeadersAlreadySentException: Headers already sent. in /home/forge/example.com/vendor/yiisoft/yii2/web/Response.php:369
            Stack trace:
            #0 {main}
            Previous exception:
            yii\base\InvalidConfigException: Unsupported configuration type: object in /home/forge/example.com/vendor/yiisoft/yii2/BaseYii.php:353
            Stack trace:
            #0 /home/forge/example.com/vendor/yiisoft/yii2/base/Component.php(764): yii\BaseYii::createObject()
            LOG);

        $this->assertSame('yii\base\InvalidConfigException', $entry->class);
        $this->assertSame('Unsupported configuration type: object', $entry->message);
        $this->assertSame(353, $entry->line);
    }

    public function test_the_last_entry_is_held_until_the_next_one_starts(): void
    {
        $parser = new CodeIgniterErrorParser;
        $first = ErrorEntries::split($parser, " 3 SYSTEMPATH/end-of-an-entry-never-sent.php\n".self::CODEIGNITER);

        $this->assertSame([], $first->entries);
        $this->assertSame(self::CODEIGNITER, $first->tail);

        $second = ErrorEntries::split($parser, $first->tail."\n 3 SYSTEMPATH/More.php(1): more()\nERROR - 2026-10-29 07:40:00 --> Next");

        $this->assertSame([self::CODEIGNITER."\n 3 SYSTEMPATH/More.php(1): more()"], $second->entries);
        $this->assertSame('ERROR - 2026-10-29 07:40:00 --> Next', $second->tail);
    }

    public function test_the_same_error_on_other_ids_has_the_same_fingerprint(): void
    {
        $parser = ErrorLogKind::Laravel->parser();
        $first = $parser->parse(self::LARAVEL);
        $second = $parser->parse(str_replace(['58003', '/home/forge/example.com/'], ['61234', '/home/forge/example.com/releases/20261029/'], self::LARAVEL));
        $other = $parser->parse(str_replace("column 'content'", "column 'title'", self::LARAVEL));

        $this->assertSame(ErrorFingerprint::of($first), ErrorFingerprint::of($second));
        $this->assertNotSame(ErrorFingerprint::of($first), ErrorFingerprint::of($other));
    }

    public function test_paths_are_kept_from_the_project_down(): void
    {
        $this->assertSame('vendor/laravel/framework/src/Foo.php', ErrorFingerprint::relativePath('/home/forge/example.com/vendor/laravel/framework/src/Foo.php'));
        $this->assertSame('app/Http/Controllers/Pay.php', ErrorFingerprint::relativePath('/home/forge/example.com/releases/123/app/Http/Controllers/Pay.php'));
        $this->assertSame('SYSTEMPATH/Security/Security.php', ErrorFingerprint::relativePath('SYSTEMPATH/Security/Security.php'));
    }
}
