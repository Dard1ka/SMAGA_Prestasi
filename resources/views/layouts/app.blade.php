{{--
    Role app shell (DESIGN-SPEC-v2 §4): sidebar per role (lg+) · top bar · content · mobile drawer menu
    · siswa tab bar (below lg) · file preview popup (<x-sth.preview>, links with data-sth-preview) · toasts.
    Anonymous component in the Livewire 4 `layouts::` namespace.

    Blade page:
        <x-layouts::app title="Kelola lomba" subtitle="Lomba yang dipublikasikan tampil di katalog siswa."
                        :badges="['kesiswaan.usulan' => $pendingCount]">
            <x-slot:actions><x-sth.button icon="plus" data-sth-open="lomba-form">Tambah lomba</x-sth.button></x-slot:actions>
            …
        </x-layouts::app>
    Livewire full-page component (default layout is layouts::app):
        #[Title('Beranda')] #[Layout('layouts::app', ['subtitle' => 'Rabu, 30 September 2026'])]
        Topbar actions there: put links/dialog openers in the page body, or @push('sth-actions').

    Props
    - title, subtitle      h1 + one line under it (title also goes into <title>)
    - role                 force a menu (siswa|guru|kesiswaan|kepala_sekolah|tata_usaha|humas|admin);
                           default: route area (siswa.*, kesiswaan.*, …) if the user has that role,
                           else the user's highest role
    - user                 defaults to auth()->user(); an array ['name' => …, 'meta' => …] for previews
    - badges               ['siswa.lomba-saya' => 2, …] (keys: App\Support\Navigation::BADGES)
    - notifications        unread count for the bell dot (default: user's unread database notifications)
    - search               false = hide · 'Cari …' = placeholder · ['action' => url, 'placeholder' => …]
    - active               force the current menu item by key ('cari-lomba', 'usulan', …); default: route match
    - preview              dev showcase only: menu items without a route look normal (href="#")
    Slots: default · actions (top bar, right) · lead (replaces the title block, e.g. breadcrumb)
--}}
@props([
    'title' => null,
    'subtitle' => null,
    'role' => null,
    'user' => null,
    'badges' => [],
    'notifications' => null,
    'search' => null,
    'active' => null,
    'preview' => false,
])
@php
    $user ??= auth()->user();
    $nav = \App\Support\Navigation::for($user, $role, (array) $badges, active: $active, preview: (bool) $preview);

    if ($search === false) {
        $nav['search'] = null;
    } elseif (is_string($search) && $nav['search'] !== null) {
        $nav['search']['placeholder'] = $search;
    } elseif (is_array($search)) {
        $nav['search'] = array_merge(['name' => 'q', 'placeholder' => 'Cari', 'action' => url()->current()], $nav['search'] ?? [], $search);
    }

    if ($notifications === null) {
        $notifications = is_object($user) && method_exists($user, 'unreadNotifications')
            ? (int) rescue(fn () => $user->unreadNotifications()->count(), 0, false)
            : 0;
    }

    $hasTabbar = $nav['tabbar'] !== [];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    @include('layouts.partials.head', ['title' => $title])
</head>
<body class="page min-h-dvh">
    <a href="#konten" class="skip-link">Lewati ke konten</a>

    <div class="flex min-h-dvh">
        <x-sth.sidebar :nav="$nav" class="hidden lg:flex" />

        <main id="konten" tabindex="-1" @class([
            'mx-auto flex w-full min-w-0 max-w-[1320px] flex-1 flex-col gap-[22px] px-4 pt-4 outline-none sm:px-6 lg:px-9 lg:pt-[26px] lg:pb-10',
            'pb-[calc(112px+env(safe-area-inset-bottom))]' => $hasTabbar,
            'pb-10' => ! $hasTabbar,
        ])>
            <x-sth.topbar :nav="$nav" :title="$title" :subtitle="$subtitle" :notifications="$notifications">
                @isset($lead)
                    <x-slot:lead>{{ $lead }}</x-slot:lead>
                @endisset
                @if (isset($actions) || trim($__env->yieldPushContent('sth-actions')) !== '')
                    <x-slot:actions>{{ $actions ?? '' }}@stack('sth-actions')</x-slot:actions>
                @endif
            </x-sth.topbar>

            {{ $slot }}
        </main>
    </div>

    <dialog id="sth-nav" class="dlg dlg-start" aria-label="Menu">
        <div class="dlg-panel">
            <x-sth.sidebar :nav="$nav" drawer />
        </div>
    </dialog>

    @if ($hasTabbar)
        <x-sth.tabbar :items="$nav['tabbar']" />
    @endif

    <x-sth.preview />
    <x-sth.flash />
    @livewireScripts
</body>
</html>
