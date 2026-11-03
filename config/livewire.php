<?php

/*
| Livewire 4 overrides for STH. Every key not listed here comes from vendor/livewire/livewire/config/livewire.php
| (Livewire's service provider merges this file over its defaults).
*/

return [

    /*
    | CSP-safe build (backlog #17, SECURITY §3f): Livewire loads livewire.csp(.min).js, whose bundled Alpine evaluates
    | directive expressions with a parser instead of eval()/new Function(), so App\Http\Middleware\SecurityHeaders
    | no longer sends 'unsafe-eval'. Expressions in wire:* / x-* attributes must stay simple: method calls with literal
    | arguments, property access, operators. No arrow functions, template literals, `??`, `?.`, globals (window,
    | document, …) or DOM property writes. Page behaviour lives in resources/js/app.js (data-sth-* hooks).
    | Guarded by tests/Feature/Security/CspSafeTest.php.
    */
    'csp_safe' => true,

    /*
    | STH: limits of the named rate limiter `livewire-update` on the Livewire update endpoint (backlog #18,
    | AppServiceProvider::configureLivewireEndpoint()): requests per minute per logged-in user, and per client IP for
    | guests (public pages, auth screens).
    */
    'sth_throttle' => [
        'user_per_minute' => (int) env('STH_LIVEWIRE_USER_PER_MINUTE', 180),
        'guest_per_minute' => (int) env('STH_LIVEWIRE_GUEST_PER_MINUTE', 120),
    ],

];
