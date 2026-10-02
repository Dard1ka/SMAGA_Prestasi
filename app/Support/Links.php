<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Decides which links may be swapped by Livewire SPA navigation (wire:navigate).
 *
 * A full page load re-downloads the CSS/JS bundle and the self-hosted fonts and reboots the framework;
 * wire:navigate swaps the <body> instead, so only the HTML travels. The <x-sth.*> link components ask
 * this class instead of each caller having to remember the rule.
 *
 * Navigable means "stays in this app and answers with HTML". Excluded:
 * - file responses under /berkas (App\Http\Controllers\FileController). AGENTS.md rule 4 sends every
 *   download through that controller, so this one prefix covers them all. PDF/XLSX exports are
 *   returned by Livewire actions (wire:click), never by a link, so they need no rule here.
 * - other hosts, mailto:/tel:/javascript:/data:, and bare in-page anchors.
 */
final class Links
{
    /** Path prefixes that answer with a file instead of HTML. */
    private const FILE_PREFIXES = ['/berkas'];

    /**
     * Public pages behind the per-IP rate limit (routes/web.php, SECURITY §3m-7). Hover prefetch would
     * roughly double the page requests from one address, and a school shares one public IP, so these
     * links navigate without prefetching.
     */
    private const THROTTLED_PREFIXES = ['/prestasi', '/peringkat', '/verifikasi-surat'];

    private const NON_PAGE_SCHEMES = ['#', 'mailto:', 'tel:', 'javascript:', 'data:'];

    public static function navigable(?string $href): bool
    {
        if (! is_string($href)) {
            return false;
        }

        $href = trim($href);

        if ($href === '' || Str::startsWith($href, self::NON_PAGE_SCHEMES)) {
            return false;
        }

        $parts = parse_url($href);

        if ($parts === false) {
            return false;
        }

        $host = $parts['host'] ?? null;

        if (is_string($host) && ! in_array(Str::lower($host), self::appHosts(), true)) {
            return false;
        }

        $path = '/'.ltrim($parts['path'] ?? '', '/');

        foreach (self::FILE_PREFIXES as $prefix) {
            if ($path === $prefix || Str::startsWith($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The Livewire attribute this link should carry, or null for a normal browser navigation.
     *
     * 'wire:navigate.hover' prefetches on hover, 'wire:navigate' only swaps on click.
     */
    public static function navigateAttribute(?string $href): ?string
    {
        if (! self::navigable($href)) {
            return null;
        }

        return self::prefetchable($href) ? 'wire:navigate.hover' : 'wire:navigate';
    }

    /**
     * May this link also be prefetched on hover (wire:navigate.hover)?
     *
     * Everything navigable except the throttled public pages — see self::THROTTLED_PREFIXES.
     */
    public static function prefetchable(?string $href): bool
    {
        if (! self::navigable($href)) {
            return false;
        }

        $path = rtrim('/'.ltrim((string) parse_url(trim((string) $href), PHP_URL_PATH), '/'), '/');

        if ($path === '') {
            return false; // the public home page
        }

        foreach (self::THROTTLED_PREFIXES as $prefix) {
            if ($path === $prefix || Str::startsWith($path, $prefix.'/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hosts that are this application: APP_URL plus the host actually being served.
     *
     * @return list<string>
     */
    private static function appHosts(): array
    {
        $hosts = [parse_url((string) config('app.url'), PHP_URL_HOST)];

        $hosts[] = rescue(fn () => Request::getHost(), null, false);

        return array_values(array_unique(array_map(
            Str::lower(...),
            array_filter($hosts, is_string(...)),
        )));
    }
}
