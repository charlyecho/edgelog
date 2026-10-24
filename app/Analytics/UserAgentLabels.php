<?php

namespace App\Analytics;

/**
 * A reader's browser and device, from the user agent. Only ever asked
 * about people, never about bots.
 */
final class UserAgentLabels
{
    /**
     * In-app browsers first: they carry a full browser's agent plus a marker.
     *
     * @var array<string, string>
     */
    private const array BROWSERS = [
        'Facebook app' => '~FBAN/|FBAV/|FB_IAB|FBIOS~i',
        'Instagram app' => '~\bInstagram\b~i',
        'LinkedIn app' => '~LinkedInApp~i',
        'Gmail app' => '~\bGmail\b|GmailWebView~i',
        'Google app' => '~\bGSA/~i',
        'Samsung Internet' => '~SamsungBrowser/~i',
        'Opera' => '~\bOPR/|\bOPT/|Opera[/ ]|OPiOS/~i',
        'Edge' => '~\bEdg(?:e|A|iOS)?/~i',
        'Yandex Browser' => '~YaBrowser/~i',
        'Firefox' => '~Firefox/|FxiOS/~i',
        'Chrome' => '~Chrome/|CriOS/|Chromium/~i',
        'Safari' => '~Version/[\d.]+.*Safari/~i',
    ];

    public static function browser(string $agent): string
    {
        foreach (self::BROWSERS as $name => $pattern) {
            if (preg_match($pattern, $agent) === 1) {
                return $name;
            }
        }

        return 'Other';
    }

    /**
     * Desktop, Mobile or Tablet. An Android browser without "Mobile" is a tablet.
     */
    public static function device(string $agent): string
    {
        return match (true) {
            preg_match('~iPad|Tablet|Kindle|Silk/|PlayBook~i', $agent) === 1 => 'Tablet',
            preg_match('~Android~i', $agent) === 1 && preg_match('~Mobile~i', $agent) !== 1 => 'Tablet',
            preg_match('~Mobi|iPhone|iPod|Android|Windows Phone~i', $agent) === 1 => 'Mobile',
            default => 'Desktop',
        };
    }
}
