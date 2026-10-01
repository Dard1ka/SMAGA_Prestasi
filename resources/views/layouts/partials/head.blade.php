{{--
    Shared <head> content for every layout. CSP: when the SecurityHeaders middleware has called
    Vite::useCspNonce(), @vite, @livewireStyles and @livewireScripts print the nonce themselves.
    No inline <script>/<style> here. Pages add extra head tags with @push('head').
    Variables: $title (page title, optional), $robots (e.g. 'noindex', optional).
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#3148D6">
@if (! empty($robots))
    <meta name="robots" content="{{ $robots }}">
@endif
<title>{{ ! empty($title) ? $title.' · ' : '' }}SMAGA Talent Hub</title>
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
@stack('head')
