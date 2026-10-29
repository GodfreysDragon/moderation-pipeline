<?php

namespace App\Webhooks;

use Closure;

/**
 * Keeps webhooks pointed at the public internet. Users choose the URL, so without
 * this check the server could be made to POST to itself, the cloud metadata
 * address or other machines on its private network.
 *
 * The URL is checked when it is saved and again before every delivery, and the
 * delivery connects to the address checked here (no second DNS lookup), so a
 * hostname can't pass the check and then resolve somewhere private.
 */
class UrlGuard
{
    /**
     * @param  (Closure(string): list<string>)|null  $lookup  hostname to IPv4 addresses
     */
    public function __construct(
        private readonly bool $allowPrivate = false,
        private readonly ?Closure $lookup = null,
    ) {}

    /**
     * @return array{host: string, port: int, ip: string}
     *
     * @throws UnsafeWebhookUrl
     */
    public function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new UnsafeWebhookUrl('The URL must start with https:// or http:// and name a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookUrl('The URL must not contain a username or password.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);

        if ($addresses === []) {
            throw new UnsafeWebhookUrl("Could not find an IPv4 address for {$host}.");
        }

        if (! $this->allowPrivate) {
            foreach ($addresses as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                    throw new UnsafeWebhookUrl("{$host} points to a private or reserved address ({$ip}). Webhooks can only go to public servers.");
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if ($this->lookup !== null) {
            return ($this->lookup)($host);
        }

        $addresses = gethostbynamel($host);

        return $addresses === false ? [] : $addresses;
    }
}
