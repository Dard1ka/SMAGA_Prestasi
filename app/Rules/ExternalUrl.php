<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * User-supplied link to a third-party site (usulan lomba, website/registration URL; SECURITY §3m-1):
 * https only, no credentials, no IP literal, no non-standard port, ≤ 500 characters (column size).
 * The URL is stored as typed and NEVER fetched by the server (no preview, favicon, liveness check).
 * Show it with ExternalUrl::host() next to the link text (anti-homograph: punycode host).
 *
 *   'website_url' => ['nullable', 'string', new ExternalUrl]
 */
final class ExternalUrl implements ValidationRule
{
    public const MAX_LENGTH = 500;

    public const MESSAGE = 'Pakai tautan lengkap yang diawali https://, tanpa alamat IP.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::passes($value)) {
            $fail(self::MESSAGE);
        }
    }

    public static function passes(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.') || str_ends_with($host, '.')) {
            return false;
        }

        // Only numbers and dots (e.g. "2130706433" style or "127.1") → treat as an IP.
        if (preg_match('/^[0-9.]+$/', $host) === 1 || str_ends_with($host, '.localhost') || $host === 'localhost') {
            return false;
        }

        return filter_var(self::asciiUrl($url, $host), FILTER_VALIDATE_URL) !== false;
    }

    /** Hostname to show next to the link, punycode for non-ASCII hosts (anti-homograph). */
    public static function host(?string $url): string
    {
        $host = is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : '';

        return self::asciiHost(strtolower($host));
    }

    private static function asciiUrl(string $url, string $host): string
    {
        return str_ireplace($host, self::asciiHost($host), $url);
    }

    private static function asciiHost(string $host): string
    {
        if ($host === '' || preg_match('/^[\x20-\x7E]+$/', $host) === 1 || ! function_exists('idn_to_ascii')) {
            return $host;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? $host : $ascii;
    }
}
