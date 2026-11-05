<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Fortify\RememberedPasswordConfirmation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the `web` group, after the session and EnsureAccountActive, so before every route middleware
 * (`password.confirm`) and every Livewire update (RequiresRecentPassword): a signed-in staff account whose browser
 * holds a valid remembered confirmation (RememberedPasswordConfirmation, the lead's request of 2026-11-05) counts as
 * having confirmed its password just now.
 */
final class RestoreRememberedPasswordConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        RememberedPasswordConfirmation::restore($request);

        return $next($request);
    }
}
