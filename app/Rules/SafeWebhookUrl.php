<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

class SafeWebhookUrl implements ValidationRule
{
    /**
     * Test-only DNS resolver override. Real resolution hits the network, which tests can't
     * (and shouldn't) depend on - set via resolveHostUsing(), reset with resolveHostUsing(null).
     *
     * @var (Closure(string): array<int, string>)|null
     */
    private static ?Closure $resolveHostUsing = null;

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver
     */
    public static function resolveHostUsing(?Closure $resolver): void
    {
        static::$resolveHostUsing = $resolver;
    }

    /**
     * Run the validation rule.
     *
     * Validates that a webhook URL is safe for server-side requests.
     * Blocks loopback addresses, cloud metadata endpoints (link-local),
     * and dangerous hostnames while allowing private network IPs
     * for self-hosted deployments.
     *
     * Known residual gap: this resolves and checks the hostname at validation time, but the
     * actual outbound request (Http::withoutRedirecting()->post(...) in the calling job) does
     * its own separate DNS lookup moments later. An attacker controlling authoritative DNS for
     * the domain with a near-zero TTL could theoretically rebind between the two lookups
     * (classic DNS-rebinding TOCTOU). Closing that fully requires resolving once and pinning the
     * actual connection to that IP - out of scope here; this closes the much more common case of
     * a hostname that resolves directly to a blocked address.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $scheme = strtolower(parse_url($value, PHP_URL_SCHEME) ?? '');
        if (! in_array($scheme, ['https', 'http'])) {
            $fail('The :attribute must use the http or https scheme.');

            return;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! $host) {
            $fail('The :attribute must contain a valid host.');

            return;
        }

        $host = strtolower($host);

        // Strip IPv6 brackets (e.g. "[::1]" -> "::1") before IP checks so bracketed
        // literals can't sneak past filter_var FILTER_VALIDATE_IP.
        $hostForIpCheck = (str_starts_with($host, '[') && str_ends_with($host, ']'))
            ? substr($host, 1, -1)
            : $host;

        // Block well-known dangerous hostnames
        $blockedHosts = ['localhost', '0.0.0.0', '::1'];
        if (in_array($hostForIpCheck, $blockedHosts) || str_ends_with($host, '.internal')) {
            Log::warning('Webhook URL points to blocked host', [
                'attribute' => $attribute,
                'host' => $host,
                'ip' => request()->ip(),
                'user_id' => auth()->id(),
            ]);
            $fail('The :attribute must not point to localhost or internal hosts.');

            return;
        }

        if (filter_var($hostForIpCheck, FILTER_VALIDATE_IP)) {
            // Block loopback (127.0.0.0/8) and link-local/metadata (169.254.0.0/16) when the
            // host is a literal IP.
            if ($this->isLoopback($hostForIpCheck) || $this->isLinkLocal($hostForIpCheck)) {
                Log::warning('Webhook URL points to blocked IP range', [
                    'attribute' => $attribute,
                    'host' => $host,
                    'ip' => request()->ip(),
                    'user_id' => auth()->id(),
                ]);
                $fail('The :attribute must not point to loopback or link-local addresses.');

                return;
            }

            return;
        }

        // The host is a hostname, not a literal IP - resolve it and check every returned
        // address, so a domain pointed directly at a blocked range can't sail through
        // unchecked just because the check above only looks at literal IPs.
        foreach ($this->resolveHost($host) as $resolvedIp) {
            if ($this->isLoopback($resolvedIp) || $this->isLinkLocal($resolvedIp)) {
                Log::warning('Webhook URL hostname resolves to a blocked IP range', [
                    'attribute' => $attribute,
                    'host' => $host,
                    'resolved_ip' => $resolvedIp,
                    'ip' => request()->ip(),
                    'user_id' => auth()->id(),
                ]);
                $fail('The :attribute must not resolve to a loopback or link-local address.');

                return;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveHost(string $host): array
    {
        if (static::$resolveHostUsing) {
            return (static::$resolveHostUsing)($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_unique(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records
        ))));
    }

    private function isLoopback(string $ip): bool
    {
        // 127.0.0.0/8, 0.0.0.0
        if ($ip === '0.0.0.0' || str_starts_with($ip, '127.')) {
            return true;
        }

        // IPv6 loopback
        $normalized = @inet_pton($ip);

        return $normalized !== false && $normalized === inet_pton('::1');
    }

    private function isLinkLocal(string $ip): bool
    {
        // 169.254.0.0/16 — covers cloud metadata at 169.254.169.254
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $long = ip2long($ip);

        return $long !== false && ($long >> 16) === (ip2long('169.254.0.0') >> 16);
    }
}
