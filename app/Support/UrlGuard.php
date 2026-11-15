<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Blocks server-side requests to user-supplied URLs that point at our own network
 * (SSRF): only public HTTPS hosts are allowed.
 */
class UrlGuard
{
    public function assertPublicHttps(string $url): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('Only https:// URLs are allowed.');
        }

        $ips = $this->resolve($parts['host']);
        if ($ips === []) {
            throw new InvalidArgumentException("Could not resolve host {$parts['host']}.");
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('URLs pointing to private or local networks are not allowed.');
            }
        }
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
