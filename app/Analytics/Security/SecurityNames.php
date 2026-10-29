<?php

namespace App\Analytics\Security;

/**
 * What a scanner probe looked for, the kinds of injection a query string
 * carries, the paths a login or form posts to, and the thresholds: data,
 * like Patterns, read by the security count.
 *
 * Which requests are probes is the classifier's business (Patterns::
 * SECRET_PROBE, SERVED_PROBE, PROBE_QUERY); these lists only say what a
 * probe was after. Each probe gets exactly one category, the first that
 * matches, else "other", so the categories add up to the probes.
 */
final class SecurityNames
{
    /**
     * What a probe looked for, in the order a probe is filed. Injection reads
     * the URL; the others the lower-cased path.
     *
     * @var array<string, string>
     */
    public const array CATEGORIES = [
        'injection' => '~\.\./|%2e%2e|/etc/passwd|\$\{jndi:|<script|%3cscript|union(?:\s|%20|\+)+select'
            .'|xdebug_session|allow_url_include|auto_prepend_file~i',
        'secrets' => '~(?:^|/)\.(?:env|git|svn|hg|bzr|aws|ssh|npmrc|htpasswd|htaccess|bash_history'
            .'|ds_store|config|vscode|idea)(?:[._/-]|$)|(?:^|/)id_(?:rsa|dsa|ecdsa|ed25519)|\.(?:pem|key|p12|pfx)$'
            .'|(?:^|/)(?:credentials?|secrets?|service-account[^/]*|firebase[^/]*|key)\.(?:json|ya?ml|xml|ini|env|txt)$'
            .'|(?:^|/)env\.js$~',
        'cloud' => '~\.tf$|\.tfvars|tfstate|terraform|(?:^|/)cdk(?:\.context)?\.json$|cdk\.out'
            .'|appspec\.ya?ml$|serverless\.ya?ml$|docker-compose|dockerfile|(?:^|/)\.docker'
            .'|kubeconfig|(?:^|/)\.kube|(?:^|/)k8s|helm|\.github/|buildspec\.ya?ml$'
            .'|cloudformation|samconfig|\.circleci|gitlab-ci|travis\.ya?ml$|vercel\.json$'
            .'|netlify\.toml$|(?:^|/)(?:config|settings|appsettings|parameters)\.(?:json|ya?ml|xml|ini)$'
            .'|containers/json~',
        'wordpress' => '~(?:^|/)(?:wp-[a-z]+|xmlrpc)(?:[/.]|$)|^/+(?:wordpress|wp)(?:[/.]|$)~',
        'dumps' => '~\.(?:sql|sqlite3?|db|bak|swp|old|orig|save|zip|tar|tgz|gz|rar|7z)$|(?:^|/)backups?(?:/|$)|(?:^|/)dump~',
        'admin' => '~(?:^|/)(?:admin|administrator|phpmyadmin|pma|myadmin|adminer|cpanel|cgi-bin'
            .'|boaform|hudson|jenkins|solr|struts|actuator|_profiler|druid|geoserver|hnap1'
            .'|owa|autodiscover|manager|console|api/config|sdk)(?:[/.]|$)|\.(?:asp|aspx|jsp|jspa|cgi|pl|cfm)(?:/|$)~',
        'php' => '~\.(?:php\d?|phtml)(?:/|$)|phpinfo|eval-stdin|invokefunction|phpunit|laravel|_ignition|telescope|_debugbar~',
    ];

    /**
     * The kinds of injection a query string carries, first match wins, else "other".
     *
     * @var array<string, string>
     */
    public const array INJECTION = [
        'traversal' => '~\.\./|%2e%2e|/etc/passwd~i',
        'log4shell' => '~\$\{jndi:~i',
        'xss' => '~<script|%3cscript~i',
        'sql' => '~union(?:\s|%20|\+)+select~i',
        'php' => '~xdebug_session|allow_url_include|auto_prepend_file~i',
    ];

    /**
     * A path segment that names signing in, a password, a sign-up or a contact form.
     */
    public const string LOGIN_PATH = '~(?:^|/)[\w.-]*(?:login|log-in|logon|signin|sign-in|sign_in|auth|session|password'
        .'|passwd|register|signup|sign-up|subscribe|contact|otp|verify|xmlrpc)[\w.-]*(?:/|$)~i';

    /**
     * Where a probed path and the class of its answer (2xx...) are joined in its row's name.
     */
    public const string ANSWER_SEPARATOR = "\t";

    public const string PHP_PATH = '~\.php\d?(?:/|$)~i';

    /**
     * The gateway errors a crashing PHP worker or upstream answers with.
     */
    public const array GATEWAY = [502, 503, 504];

    /**
     * How much a day keeps.
     */
    public const int SUSPECTS = 300;

    public const int EXPOSURES = 200;

    public const int EPISODES = 100;

    public const int ROW_PATHS = 20;

    /**
     * The label of each category, for people.
     *
     * @return array<string, string>
     */
    public static function categoryLabels(): array
    {
        return [
            'injection' => __('Path tricks and injection'),
            'secrets' => __('Secret files (.env, keys)'),
            'cloud' => __('Cloud and deploy configs'),
            'wordpress' => __('WordPress'),
            'dumps' => __('Database dumps and backups'),
            'admin' => __('Admin panels and consoles'),
            'php' => __('PHP scripts'),
            'other' => __('Other probes'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function injectionLabels(): array
    {
        return [
            'traversal' => __('Path traversal (../, /etc/passwd)'),
            'log4shell' => __('Log4Shell (${jndi:)'),
            'xss' => __('Script injection (<script)'),
            'sql' => __('SQL injection (union select)'),
            'php' => __('PHP settings and debugger'),
            'other' => __('Other injection'),
        ];
    }

    public static function category(string $path, string $url): string
    {
        $lower = strtolower($path);

        foreach (self::CATEGORIES as $key => $pattern) {
            if (preg_match($pattern, $key === 'injection' ? $url : $lower) === 1) {
                return $key;
            }
        }

        return 'other';
    }

    public static function injection(string $url): string
    {
        foreach (self::INJECTION as $key => $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return $key;
            }
        }

        return 'other';
    }

    /**
     * Determine if a Host header is an IP literal, with or without a port:
     * the request went to the server's address, not to the site.
     */
    public static function isBareIp(string $host): bool
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, '[')) {
            $host = explode(']', substr($host, 1), 2)[0];
        } elseif (substr_count($host, ':') === 1) {
            $host = explode(':', $host, 2)[0];
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * The network an address belongs to: its /24 (IPv4) or /48 (IPv6).
     */
    public static function network(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return implode('.', array_slice(explode('.', $address), 0, 3)).'.0/24';
        }

        $packed = @inet_pton($address);

        if ($packed === false) {
            return $address;
        }

        return inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10)).'/48';
    }
}
