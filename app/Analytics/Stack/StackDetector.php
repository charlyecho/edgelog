<?php

namespace App\Analytics\Stack;

use Illuminate\Http\Client\Response;

/**
 * What a host's answer says about what runs it: the Server and
 * X-Powered-By headers, its cookies, the page's generator tag and the
 * paths it loads. A version the server does not show is null: never guessed.
 */
final class StackDetector
{
    /**
     * Products by what serves them, for people.
     *
     * @var array<string, string>
     */
    public const array NAMES = [
        'nginx' => 'nginx', 'openresty' => 'OpenResty', 'apache' => 'Apache', 'litespeed' => 'LiteSpeed', 'caddy' => 'Caddy',
        'iis' => 'Microsoft IIS', 'cloudflare' => 'Cloudflare', 'php' => 'PHP', 'nodejs' => 'Node.js', 'express' => 'Express',
        'nextjs' => 'Next.js', 'nuxt' => 'Nuxt', 'aspnet' => 'ASP.NET', 'wordpress' => 'WordPress', 'drupal' => 'Drupal',
        'joomla' => 'Joomla', 'magento' => 'Magento', 'laravel' => 'Laravel', 'codeigniter' => 'CodeIgniter',
        'symfony' => 'Symfony', 'django' => 'Django', 'rails' => 'Ruby on Rails',
    ];

    /**
     * The command that shows a product's version on the server.
     *
     * @var array<string, string>
     */
    public const array COMMANDS = [
        'nginx' => 'nginx -v', 'apache' => 'apachectl -v', 'php' => 'php -v', 'nodejs' => 'node -v', 'caddy' => 'caddy version',
        'wordpress' => 'wp core version', 'laravel' => 'php artisan --version', 'drupal' => 'drush status',
        'symfony' => 'php bin/console --version', 'django' => 'python -m django --version', 'rails' => 'rails -v',
        'codeigniter' => 'grep CI_VERSION system/core/CodeIgniter.php',
    ];

    /**
     * Each product found, with its version when shown, and where it was read.
     *
     * @return array<string, array{version: ?string, source: string}>
     */
    public static function detect(Response $response): array
    {
        $found = [];
        $add = function (string $product, ?string $version, string $source) use (&$found) {
            if (! isset($found[$product]) || ($found[$product]['version'] === null && $version !== null)) {
                $found[$product] = ['version' => $version ?: null, 'source' => $source];
            }
        };

        $server = $response->header('Server');
        $servers = ['nginx' => 'nginx', 'openresty' => 'openresty', 'apache' => 'Apache', 'litespeed' => 'LiteSpeed', 'caddy' => 'Caddy', 'iis' => 'Microsoft-IIS', 'cloudflare' => 'cloudflare'];

        foreach ($servers as $product => $token) {
            if (preg_match('~(?:^|\s)'.preg_quote($token, '~').'(?:/([\d.]+))?~i', $server, $match) === 1) {
                $add($product, $match[1] ?? null, 'Server');
            }
        }

        $powered = implode(', ', $response->toPsrResponse()->getHeader('X-Powered-By'));

        foreach (['php' => 'PHP', 'express' => 'Express', 'nextjs' => 'Next\.js', 'aspnet' => 'ASP\.NET'] as $product => $token) {
            if (preg_match('~'.$token.'(?:/([\d.]+))?~i', $powered, $match) === 1) {
                $add($product, $match[1] ?? null, 'X-Powered-By');
            }
        }

        $cookies = strtolower(implode("\n", $response->toPsrResponse()->getHeader('Set-Cookie')));

        foreach (['php' => 'phpsessid=', 'codeigniter' => 'ci_session=', 'laravel' => 'laravel_session=', 'symfony' => 'sf_redirect=', 'django' => 'csrftoken=', 'magento' => 'frontend='] as $product => $cookie) {
            if (str_contains($cookies, $cookie)) {
                $add($product, null, __('cookie :name', ['name' => rtrim($cookie, '=')]));
            }
        }

        // PHP's session handler sends this date when it forbids caching.
        if (str_contains($response->header('Expires'), '19 Nov 1981')) {
            $add('php', null, __('PHP\'s session headers'));
        }

        $body = substr($response->body(), 0, 2_000_000);

        if (preg_match('~<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)~i', $body, $generator) === 1) {
            foreach (['wordpress' => 'WordPress', 'drupal' => 'Drupal', 'joomla' => 'Joomla!?'] as $product => $token) {
                if (preg_match('~'.$token.'\s*([\d.]+)?~i', $generator[1], $match) === 1) {
                    $add($product, $match[1] ?? null, __('generator tag'));
                }
            }
        }

        if (preg_match('~/wp-(?:content|includes)/~', $body) === 1) {
            $add('wordpress', null, __('its /wp-content/ paths'));
        }

        if (str_contains($body, '/_next/')) {
            $add('nextjs', null, __('its /_next/ paths'));
        }

        if (str_contains($body, '__NUXT')) {
            $add('nuxt', null, __('its Nuxt payload'));
        }

        foreach (['codeigniter', 'laravel', 'symfony', 'wordpress', 'drupal', 'joomla', 'magento'] as $product) {
            if (isset($found[$product])) {
                $add('php', null, __(':name runs on PHP', ['name' => self::NAMES[$product]]));
            }
        }

        foreach (['nextjs', 'nuxt', 'express'] as $product) {
            if (isset($found[$product])) {
                $add('nodejs', null, __(':name runs on Node.js', ['name' => self::NAMES[$product]]));
            }
        }

        return $found;
    }
}
