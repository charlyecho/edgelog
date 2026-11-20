<?php

namespace App\Analytics\Stack;

/**
 * Whether a TCP port of a server answers from outside: a connection opened
 * then closed, nothing sent. Only a public address is tried, and never one
 * of this machine's own: traffic to itself does not cross its firewall.
 */
class PortProbe
{
    public const float TIMEOUT = 3.0;

    /**
     * True when the port answers, false when it does not, null when it
     * cannot be told from here.
     */
    public function answers(string $address, int $port): ?bool
    {
        if (! self::isPublic($address) || $this->isOwnAddress($address)) {
            return null;
        }

        $host = str_contains($address, ':') ? "[{$address}]" : $address;
        $stream = @stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, self::TIMEOUT);

        if ($stream === false) {
            return false;
        }

        fclose($stream);

        return true;
    }

    /**
     * Whether an address is one of the internet's: not private, not reserved.
     */
    public static function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Whether this machine holds the address: only then can it listen on it.
     */
    protected function isOwnAddress(string $address): bool
    {
        $host = str_contains($address, ':') ? "[{$address}]" : $address;
        $server = @stream_socket_server("tcp://{$host}:0", $errorCode, $errorMessage);

        if ($server === false) {
            return false;
        }

        fclose($server);

        return true;
    }
}
