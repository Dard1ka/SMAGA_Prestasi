<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * User-supplied link to a third-party site (usulan lomba, website/registration URL; SECURITY §3m-1):
 * https only, no credentials, no IP literal, no non-standard port, ≤ 500 characters (column size).
 * The forms clean a pasted link at both ends first (normalize()); what is left is stored as typed and NEVER
 * fetched by the server (no preview, favicon, liveness check). The top-level domain is not judged.
 * Show it with ExternalUrl::host() next to the link text (anti-homograph: punycode host).
 *
 *   'website_url' => ['nullable', 'string', new ExternalUrl]
 *
 * A refused link is told why (lead's mail test, 2026-10-09: one sentence for every reason made a typo look like a
 * random refusal): problem() returns one sentence per reason, passes() is "no problem". The gates that decide are
 * the ones the rule always had, in the same order; the sentences only explain them. Stored links are checked
 * again with passes() on every display and on every save of a lomba, so the accepted set must never shrink
 * (tests/Unit/Rules/ExternalUrlTest.php compares it with the rule as it was).
 */
final class ExternalUrl implements ValidationRule
{
    public const MAX_LENGTH = 500;

    /** Fallback: an empty text or something that is no text at all. */
    public const MESSAGE = 'Link belum benar. Salin link lengkap dari kolom alamat browser.';

    public const NO_HTTPS = 'Link harus diawali https://. Contoh: https://www.example.com/lomba';

    public const HTTP = 'Link harus diawali https://, bukan http://.';

    public const SPACE = 'Link tidak boleh berisi spasi.';

    public const HOST = 'Nama situs pada link ini belum benar. Contoh: https://www.example.com/lomba';

    public const IP = 'Pakai nama situs, bukan alamat IP atau localhost.';

    public const PORT = 'Link tidak boleh memakai nomor port, misalnya :8080.';

    public const CREDENTIALS = 'Link tidak boleh berisi nama pengguna atau kata sandi.';

    public const CHARACTERS = 'Link berisi karakter yang tidak bisa dipakai. Salin ulang link dari kolom alamat browser.';

    public const TOO_LONG = 'Link terlalu panjang, maksimal 500 karakter.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = is_string($value) ? self::problem($value) : self::MESSAGE;

        if ($problem !== null) {
            $fail($problem);
        }
    }

    public static function passes(string $url): bool
    {
        return self::problem($url) === null;
    }

    /**
     * A link field as typed or pasted → the text to check and store. Livewire hands text over untrimmed, and a link
     * copied from a chat, a PDF or a document brings spaces, line breaks or invisible characters along. Removed:
     * byte order mark, zero-width characters, soft hyphens and text-direction marks anywhere; whitespace of any kind
     * (no-break and ideographic spaces too) at both ends. A space inside the link stays and is refused with its own
     * sentence. Nothing is added or repaired: no scheme, no "www". Anything that is not text gives ''.
     */
    public static function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $clean = preg_replace('/[\x{FEFF}\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}]/u', '', $value);
        $clean = $clean === null ? null : preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $clean);

        // null = the text is not valid UTF-8: trim the plain way and let problem() refuse what is left.
        return $clean ?? trim($value);
    }

    /** Why the link is refused, as one sentence for the user; null when it is accepted. */
    public static function problem(string $url): ?string
    {
        if ($url === '') {
            return self::MESSAGE;
        }

        if (strlen($url) > self::MAX_LENGTH) {
            return self::TOO_LONG;
        }

        if (preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            // Tab and line breaks count as spaces; the other control characters cannot be typed on purpose.
            return preg_match('/[\x00-\x08\x0E-\x1F\x7F]/', $url) === 1 ? self::CHARACTERS : self::SPACE;
        }

        $parts = parse_url($url);

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            return self::unreadable($url);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::CREDENTIALS;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return self::PORT;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        // An IP literal, only numbers and dots (e.g. "2130706433" style or "127.1") or this machine itself.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/^[0-9.]+$/', $host) === 1
            || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            return self::IP;
        }

        if (! str_contains($host, '.') || str_ends_with($host, '.')) {
            return self::HOST;
        }

        if (filter_var(self::asciiUrl($url, $host), FILTER_VALIDATE_URL) !== false) {
            return null;
        }

        // PHP's URL filter said no. It names no reason, so look for the usual ones.
        if (preg_match('/\p{Z}/u', $url) === 1) {
            return self::SPACE;
        }

        if (filter_var(self::asciiHost($host), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return self::HOST;
        }

        return self::CHARACTERS;
    }

    /** Hostname to show next to the link, punycode for non-ASCII hosts (anti-homograph). */
    public static function host(?string $url): string
    {
        $host = is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : '';

        return self::asciiHost(strtolower($host));
    }

    /**
     * The sentence for a text PHP cannot read as "https://host…": it does not start with https://, or what follows
     * has no site name in it.
     */
    private static function unreadable(string $url): string
    {
        if (preg_match('~^https://~i', $url) !== 1) {
            return preg_match('~^http://~i', $url) === 1 ? self::HTTP : self::NO_HTTPS;
        }

        // A port PHP refuses to parse ("https://situs.example:99999/") is still a port to the reader.
        if (preg_match('~^https://[^/?#@]*:(\d+)(?:[/?#]|$)~i', $url, $match) === 1 && $match[1] !== '443') {
            return self::PORT;
        }

        return self::HOST;
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
