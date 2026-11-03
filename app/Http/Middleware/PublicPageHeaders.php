<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Livewire\Publik\Data\PublicSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public pages (W2-D, SECURITY §3m-7): `X-Robots-Tag: noindex, nofollow` unless Admin allowed indexing of
 * the aggregate pages. Student pages (/prestasi/siswa/{slug}, or /prestasi?siswa=…) are always noindex.
 */
final class PublicPageHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $studentPage = $request->routeIs('public.prestasi.siswa') || $request->query->has('siswa');
        $robots = PublicSettings::robots(studentPage: $studentPage);

        if (str_starts_with($robots, 'noindex')) {
            $response->headers->set('X-Robots-Tag', $robots);
        }

        return $response;
    }
}
