<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global security headers (docs/SECURITY.md §3f). Registered with $middleware->append() in bootstrap/app.php.
 *
 * - script-src / style-src use a per-request nonce (Vite::useCspNonce(); Livewire picks the same nonce up for
 *
 *   @livewireScripts/@livewireStyles). No inline <script>/onclick, no style="" attributes (they are blocked).
 * - 'unsafe-eval' is added only while config('livewire.csp_safe') is false (Alpine's standard build needs it).
 * - Local + Vite dev server (public/hot): the dev origin is allowed and styles fall back to 'unsafe-inline'
 *   because Vite injects <style> tags without a nonce.
 * - HSTS is NOT set here: the host Apache vhost owns it (§3e).
 * - A response that already carries its own CSP (e.g. a sandboxed file download) keeps it.
 */
class SecurityHeaders
{
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
        $style = ["'self'", "'nonce-{$nonce}'"];
        $connect = ["'self'"];
        $font = ["'self'", 'data:'];

        if (! config('livewire.csp_safe', false)) {
            $script[] = "'unsafe-eval'";
        }

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
