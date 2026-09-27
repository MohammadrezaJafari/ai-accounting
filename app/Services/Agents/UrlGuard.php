<?php

namespace App\Services\Agents;

use Closure;

/**
 * Agents fetch customer-supplied URLs from our servers; only public http(s) addresses are
 * allowed, so a source cannot point at localhost, the private network or cloud metadata.
 */
class UrlGuard
{
    /** @var Closure(string): list<string> host → IP addresses */
    private Closure $resolver;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? fn (string $host): array => array_values(array_filter([
            ...(gethostbynamel($host) ?: []),
            ...array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        ]));
    }

    public function assertPublic(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = trim($parts['host'] ?? '', '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new AgentException("آدرس «{$url}» معتبر نیست.");
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);

        if ($addresses === []) {
            throw new AgentException("دامنهٔ «{$host}» پیدا نشد.");
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new AgentException("آدرس «{$url}» به شبکهٔ داخلی اشاره می‌کند و مجاز نیست.");
            }
        }
    }
}
