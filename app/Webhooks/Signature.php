<?php

namespace App\Webhooks;

/**
 * The Moderation-Signature header: "t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">".
 * Receivers recompute the HMAC with their endpoint secret and reject old timestamps
 * to stop replays.
 */
final class Signature
{
    public const HEADER = 'Moderation-Signature';

    public static function make(string $body, string $secret, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    public static function verify(string $header, string $body, string $secret, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        if (! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m)) {
            return false;
        }

        if (abs(($now ?? time()) - (int) $m[1]) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$m[1]}.{$body}", $secret), $m[2]);
    }
}
