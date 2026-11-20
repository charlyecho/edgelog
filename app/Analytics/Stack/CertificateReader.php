<?php

namespace App\Analytics\Stack;

/**
 * The TLS certificate a host shows on port 443: when it ends, who issued
 * it, the names it covers, and whether a browser trusts it (its chain and
 * its name). A certificate that is not trusted is still read, so an expired
 * one says since when.
 */
class CertificateReader
{
    public const int TIMEOUT = 10;

    /**
     * The host's certificate; null when the host does not answer TLS.
     *
     * @return ?array{expires_at: string, issuer: ?string, names: list<string>, trusted: bool}
     */
    public function read(string $host): ?array
    {
        $trusted = $this->certificate($host, verify: true);
        $certificate = $trusted ?? $this->certificate($host, verify: false);

        if ($certificate === null) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate);

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            return null;
        }

        $names = array_values(array_filter(array_map(
            fn (string $name) => str_starts_with(trim($name), 'DNS:') ? substr(trim($name), 4) : null,
            explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')),
        )));

        return [
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $parsed['validTo_time_t']),
            'issuer' => $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null,
            'names' => array_slice($names, 0, 50),
            'trusted' => $trusted !== null,
        ];
    }

    /**
     * @return \OpenSSLCertificate|null
     */
    private function certificate(string $host, bool $verify): mixed
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);

        $stream = @stream_socket_client("ssl://{$host}:443", $errorCode, $errorMessage, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            return null;
        }

        $certificate = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
        fclose($stream);

        return $certificate ?: null;
    }
}
