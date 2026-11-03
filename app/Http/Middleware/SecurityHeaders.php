<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global security headers (docs/SECURITY.md §3f). Registered with $middleware->append() in bootstrap/app.php.
 *
 * - script-src / style-src use a per-request nonce (Vite::useCspNonce(); Livewire picks the same nonce up for
 *
 *   @livewireScripts/@livewireStyles). No inline <script>/onclick, no style="" attributes (they are blocked).
 * - style-src also allows the `<style data-livewire-style>` of @livewireStyles by its hash (QA8, backlog #52): a
 *   `wire:navigate` page change puts the next page's copy of it into the open page with the next page's nonce, which
 *   the open page's CSP does not know, so every page change logged a blocked style. Its content is the same on every
 *   page; the hash is taken from the installed Livewire, so an upgrade that changes the style changes the hash too.
 * - Never 'unsafe-eval' (backlog #17): config/livewire.php sets `csp_safe`, so Livewire ships its CSP build whose Alpine
 *   parses directive expressions without eval(). Keep it on; tests/Feature/Security/CspSafeTest.php guards both.
 * - Local + Vite dev server (public/hot): the dev origin is allowed and styles fall back to 'unsafe-inline'
 *   because Vite injects <style> tags without a nonce.
 * - HSTS is NOT set here: the host Apache vhost owns it (§3e).
 * - A response that already carries its own CSP (e.g. a sandboxed file download) keeps it.
 * - Frames (M3-C): pages may frame this origin only (`frame-src 'self'`, written out although `default-src`
 *   already meant the same) and may themselves never be framed (`frame-ancestors 'none'`, `X-Frame-Options:
 *   DENY`). The one answer that may sit in a frame is the inline PDF of the preview popup, which sets its own
 *   `frame-ancestors 'self'` + `X-Frame-Options: SAMEORIGIN` (FileController); a header a response sets itself
 *   is kept here. No `blob:`, `data:` or other origin in `frame-src`, and `object-src` stays 'none'.
 */
class SecurityHeaders
{
    /** CSP source of the Livewire style (`'sha256-…'`), worked out once per process; '' when there is none. */
    private static ?string $livewireStyle = null;

    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        if (! $headers->has('Content-Security-Policy') && ! $headers->has('Content-Security-Policy-Report-Only')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($request, (string) Vite::cspNonce()));
        }

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        $headers->remove('X-Powered-By');

        return $response;
    }

    private function contentSecurityPolicy(Request $request, string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $style = array_values(array_filter(["'self'", "'nonce-{$nonce}'", self::livewireStyleSource()]));
        $connect = ["'self'"];
        $font = ["'self'", 'data:'];

        // Vite dev server (npm run dev) — local only.
        if (app()->environment('local') && ($hot = $this->viteDevServer()) !== null) {
            $script[] = $hot;
            $style = ["'self'", "'unsafe-inline'", $hot];
            $font[] = $hot;
            $connect[] = $hot;
            $connect[] = (string) preg_replace('#^http#', 'ws', $hot);
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            "img-src 'self' data: blob:",
            'font-src '.implode(' ', $font),
            'connect-src '.implode(' ', $connect),
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        if ($request->isSecure() || app()->isProduction()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }

    /**
     * `'sha256-…'` of the content of the `<style>` Livewire prints with @livewireStyles. FrontendAssets::styles() prints it
     * only once per request (`hasRenderedStyles`), so the flag is set back afterwards and the page keeps its own copy.
     */
    public static function livewireStyleSource(): string
    {
        if (self::$livewireStyle !== null) {
            return self::$livewireStyle;
        }

        $assets = app(FrontendAssets::class);
        $rendered = $assets->hasRenderedStyles;
        $assets->hasRenderedStyles = false;

        try {
            $html = FrontendAssets::styles(['nonce' => 'hash']);
        } finally {
            $assets->hasRenderedStyles = $rendered;
        }

        return self::$livewireStyle = preg_match('#<style[^>]*>(.*?)</style>#s', $html, $style) === 1
            ? "'sha256-".base64_encode(hash('sha256', $style[1], true))."'"
            : '';
    }

    private function viteDevServer(): ?string
    {
        $hotFile = public_path('hot');

        if (! is_file($hotFile)) {
            return null;
        }

        $url = rtrim(trim((string) file_get_contents($hotFile)), '/');

        return preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+$#', $url) === 1 ? $url : null;
    }
}
