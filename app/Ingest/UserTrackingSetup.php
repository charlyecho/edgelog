<?php

namespace App\Ingest;

/**
 * How a site's app names its signed-in user in the access log: a response
 * header the web server writes at the end of the line as uid=, and takes
 * off the response, so the browser never sees it.
 */
final class UserTrackingSetup
{
    public const string HEADER = 'X-Edgelog-User';

    /**
     * A Laravel middleware that sends a pseudonymous id of the signed-in user.
     */
    public static function laravel(): string
    {
        return <<<'PHP'
            // app/Http/Middleware/TellEdgelogTheUser.php, added to the "web" group
            public function handle(Request $request, Closure $next): Response
            {
                $response = $next($request);

                if ($user = $request->user()) {
                    // A keyed hash: Edgelog follows the person without knowing who they are.
                    $response->headers->set('X-Edgelog-User', hash_hmac('sha256', (string) $user->getAuthIdentifier(), config('app.key')));
                }

                return $response;
            }
            PHP;
    }

    /**
     * The same in plain PHP, before any output.
     */
    public static function php(): string
    {
        return <<<'PHP'
            if (isset($_SESSION['user_id'])) {
                header('X-Edgelog-User: '.hash_hmac('sha256', (string) $_SESSION['user_id'], getenv('APP_SECRET')));
            }
            PHP;
    }

    /**
     * nginx: write the header at the end of the line, never pass it to the browser.
     */
    public static function nginx(): string
    {
        return <<<'NGINX'
            # At the end of the log_format, inside its quotes:
            #     ' uid=$upstream_http_x_edgelog_user'
            # Then, where the app is passed to PHP-FPM (or proxied):
            fastcgi_hide_header X-Edgelog-User;   # proxy_hide_header X-Edgelog-User; behind proxy_pass
            NGINX;
    }

    /**
     * Apache: the same, with mod_headers taking the header off the response.
     */
    public static function apache(): string
    {
        return <<<'APACHE'
            # At the end of the LogFormat, inside its quotes:   uid=%{X-Edgelog-User}o
            Header always unset X-Edgelog-User
            APACHE;
    }
}
