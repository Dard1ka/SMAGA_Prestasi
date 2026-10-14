{{--
    App top bar (DESIGN-SPEC-v3 §4): left = page title (h1, .title-page) + one-line subtitle, or the
    `lead` slot (breadcrumb); right = actions slot, search (GET ?q= to the role's list page), bell with
    unread dot, avatar button with the account menu (Notifikasi, Keamanan akun, Keluar). Below lg a menu button
    opens the drawer <dialog id="sth-nav"> rendered by the app layout.

    Rendered by <x-layouts::app>; use directly only for custom shells.
    <x-sth.topbar :nav="$nav" title="Kelola lomba" subtitle="Lomba yang dipublikasikan tampil di katalog siswa." :notifications="2">
        <x-slot:actions><x-sth.button icon="plus" data-sth-open="lomba-form">Tambah lomba</x-sth.button></x-slot:actions>
    </x-sth.topbar>

    Props: nav (Navigation::for) · title · subtitle · notifications (unread count)
           counts-url (address app.js asks for fresh counts, null = never ask) · counts-every (seconds between
           two background checks); both come from the app layout (NotificationCountController::pollUrl / pollSeconds)
    Slots: lead (replaces title block) · actions

    Live bell (D-127): the bubble is [data-sth-badge="akun.notifikasi"] with data-sth-badge-max="99" (shows "99+")
    and data-sth-count (the real number); the button is [data-sth-badge-label="akun.notifikasi"] with
    data-label-none / data-label-some (":n" = the number). A screen changes them with
        $this->dispatch('sth-badges', badges: [Navigation::BELL => $unread]);
--}}
@props(['nav', 'title' => null, 'subtitle' => null, 'notifications' => 0, 'countsUrl' => null, 'countsEvery' => 60])
@php
    $search = $nav['search'] ?? null;
    $count = max(0, (int) $notifications);
    $bellKey = \App\Support\Navigation::BELL;
    $notifUrl = \Illuminate\Support\Facades\Route::has('notifikasi.index') ? route('notifikasi.index') : null;
    $securityUrl = \Illuminate\Support\Facades\Route::has('akun.keamanan') ? route('akun.keamanan') : null;
    $canLogout = \Illuminate\Support\Facades\Route::has('logout');
    $user = $nav['user'];
    $query = $search ? request()->query($search['name']) : null;
    $query = is_string($query) ? $query : '';
    // One way to each place: below lg the search icon is left out when a tab of the tab bar opens the same page
    // (siswa: tab "Lomba" = Cari lomba, where the search box is).
    $searchInTabs = $search !== null && collect($nav['tabbar'] ?? [])->contains(fn (array $tab): bool => ($tab['href'] ?? null) === $search['action']);
@endphp
<header {{ $attributes->class('flex items-center justify-between gap-3 lg:gap-6') }}>
    <div class="flex min-w-0 items-center gap-3">
        <x-sth.icon-button icon="menu" label="Buka menu" class="lg:hidden" data-sth-open="sth-nav" aria-haspopup="dialog" aria-controls="sth-nav" />
        <div class="flex min-w-0 flex-col gap-1">
            @isset($lead)
                {{ $lead }}
            @else
                @if ($title)
                    {{-- Phones: one size down and free to wrap, so "Persetujuan" or "Dashboard prestasi" is never cut. --}}
                    <h1 class="title-page max-sm:text-xl sm:truncate">{{ $title }}</h1>
                @endif
                @if ($subtitle)
                    {{-- data-sth-subtitle: a screen whose tabs change without a page load sends the line of the open tab
                         (window event sth-subtitle, resources/js/app.js), e.g. Cari lomba › Usulan saya. --}}
                    <p class="m-0 hidden text-md leading-[1.4] text-muted sm:block" data-sth-subtitle>{{ $subtitle }}</p>
                @endif
            @endisset
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-2 sm:gap-3">
        @isset($actions)
            <div class="hidden items-center gap-2.5 md:flex">{{ $actions }}</div>
        @endisset

        @if ($search)
            {{-- data-sth-live-search: on the list page itself app.js filters while typing (no Enter); elsewhere Enter = GET. --}}
            <form role="search" method="GET" action="{{ $search['action'] }}" data-sth-live-search class="relative hidden w-[240px] lg:block xl:w-[300px]">
                <label class="sr-only" for="cari-atas">{{ $search['placeholder'] }}</label>
                <x-sth.icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-placeholder" />
                <input class="input pl-11" id="cari-atas" type="search" name="{{ $search['name'] }}" value="{{ $query }}" placeholder="{{ $search['placeholder'] }}" autocomplete="off">
            </form>
            @unless ($searchInTabs)
                <x-sth.icon-button icon="search" :label="$search['placeholder']" :href="$search['action']" class="lg:hidden" />
            @endunless
        @endif

        {{-- B1: bell → notifikasi.index; unread count bubble (99+) instead of the plain dot.
             D-127: the bubble is always printed (hidden at 0) and the button carries both label texts, so the
             browser event `sth-badges` (key App\Support\Navigation::BELL) shows, changes and hides the number and
             rewrites the aria-label without a page load. With `counts-url` app.js also asks the server for fresh
             counts while the page stays open. No display class on the bubble: `hidden` has to win. --}}
        <span class="relative inline-flex" @if ($countsUrl) data-sth-counts-url="{{ $countsUrl }}" data-sth-counts-every="{{ (int) $countsEvery }}" @endif>
            <x-sth.icon-button
                icon="bell"
                :label="$count > 0 ? 'Notifikasi, '.$count.' belum dibaca' : 'Notifikasi'"
                :href="$notifUrl"
                :disabled="$notifUrl === null"
                :data-sth-badge-label="$bellKey"
                data-label-none="Notifikasi"
                data-label-some="Notifikasi, :n belum dibaca"
            />
            <span class="pointer-events-none absolute -top-1 -right-1 min-w-5 rounded-full border-2 border-white bg-red px-1 text-center text-xs leading-4 font-bold text-white" aria-hidden="true" data-sth-badge="{{ $bellKey }}" data-sth-badge-max="99" data-sth-count="{{ $count }}" @if ($count === 0) hidden @endif>{{ $count > 99 ? '99+' : $count }}</span>
        </span>

        <div class="relative" data-sth-menu>
            <button type="button" class="flex rounded-full" aria-expanded="false" aria-controls="sth-user-menu" data-sth-menu-button>
                <x-sth.avatar :name="$user['name']" size="lg" />
                <span class="sr-only">Akun {{ $user['name'] }}</span>
            </button>
            <div id="sth-user-menu" class="menu" hidden data-sth-menu-panel>
                <div class="mb-1.5 border-b border-line px-3 pt-2 pb-2.5">
                    <p class="m-0 text-sm font-bold">{{ $user['name'] }}</p>
                    <p class="m-0 text-xs text-muted">{{ $user['meta'] }}</p>
                </div>
                {{-- W4: account items of every role (siswa also has them in the sidebar "Akun" section). --}}
                @if ($notifUrl)
                    <a class="menu-item" href="{{ $notifUrl }}" @if ($navAttr = \App\Support\Links::navigateAttribute($notifUrl)) {{ $navAttr }} @endif><x-sth.icon name="bell" :size="18" />Notifikasi</a>
                @endif
                @if ($securityUrl)
                    <a class="menu-item" href="{{ $securityUrl }}" @if ($navAttr = \App\Support\Links::navigateAttribute($securityUrl)) {{ $navAttr }} @endif><x-sth.icon name="lock" :size="18" />Keamanan akun</a>
                @endif
                @if ($canLogout)
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="menu-item text-red hover:text-red"><x-sth.icon name="logout" :size="18" />Keluar</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</header>
@isset($actions)
    <div class="-mt-2 flex flex-wrap items-center gap-2.5 md:hidden">{{ $actions }}</div>
@endisset
