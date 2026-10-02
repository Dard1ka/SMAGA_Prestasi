{{--
    App top bar (DESIGN-SPEC-v2 §4): left = page title (h1 30px Urbanist) + one-line subtitle, or the
    `lead` slot (breadcrumb); right = actions slot, search (GET ?q= to the role's list page), bell with
    unread dot, avatar button with the account menu (Notifikasi, Keamanan akun, Keluar). Below lg a menu button
    opens the drawer <dialog id="sth-nav"> rendered by the app layout.

    Rendered by <x-layouts::app>; use directly only for custom shells.
    <x-sth.topbar :nav="$nav" title="Kelola lomba" subtitle="Lomba yang dipublikasikan tampil di katalog siswa." :notifications="2">
        <x-slot:actions><x-sth.button icon="plus" data-sth-open="lomba-form">Tambah lomba</x-sth.button></x-slot:actions>
    </x-sth.topbar>

    Props: nav (Navigation::for) · title · subtitle · notifications (unread count)
    Slots: lead (replaces title block) · actions
--}}
@props(['nav', 'title' => null, 'subtitle' => null, 'notifications' => 0])
@php
    $search = $nav['search'] ?? null;
    $count = (int) $notifications;
    $notifUrl = \Illuminate\Support\Facades\Route::has('notifikasi.index') ? route('notifikasi.index') : null;
    $securityUrl = \Illuminate\Support\Facades\Route::has('akun.keamanan') ? route('akun.keamanan') : null;
    $canLogout = \Illuminate\Support\Facades\Route::has('logout');
    $user = $nav['user'];
    $query = $search ? request()->query($search['name']) : null;
    $query = is_string($query) ? $query : '';
@endphp
<header {{ $attributes->class('flex items-center justify-between gap-3 lg:gap-6') }}>
    <div class="flex min-w-0 items-center gap-3">
        <x-sth.icon-button icon="menu" label="Buka menu" class="lg:hidden" data-sth-open="sth-nav" aria-haspopup="dialog" aria-controls="sth-nav" />
        <div class="flex min-w-0 flex-col gap-1">
            @isset($lead)
                {{ $lead }}
            @else
                @if ($title)
                    <h1 class="title-page truncate">{{ $title }}</h1>
                @endif
                @if ($subtitle)
                    <p class="m-0 hidden text-[15px] leading-[1.4] text-muted sm:block">{{ $subtitle }}</p>
                @endif
            @endisset
        </div>
    </div>

    <div class="flex shrink-0 items-center gap-2 sm:gap-3">
        @isset($actions)
            <div class="hidden items-center gap-2.5 md:flex">{{ $actions }}</div>
        @endisset

        @if ($search)
            <form role="search" method="GET" action="{{ $search['action'] }}" class="relative hidden w-[240px] lg:block xl:w-[300px]">
                <label class="sr-only" for="cari-atas">{{ $search['placeholder'] }}</label>
                <x-sth.icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-placeholder" />
                <input class="input pl-11" id="cari-atas" type="search" name="{{ $search['name'] }}" value="{{ $query }}" placeholder="{{ $search['placeholder'] }}" autocomplete="off">
            </form>
            <x-sth.icon-button icon="search" :label="$search['placeholder']" :href="$search['action']" class="lg:hidden" />
        @endif

        {{-- B1: bell → notifikasi.index; unread count badge (99+) instead of the plain dot. --}}
        <span class="relative inline-flex">
            <x-sth.icon-button
                icon="bell"
                :label="$count > 0 ? 'Notifikasi, '.$count.' belum dibaca' : 'Notifikasi'"
                :href="$notifUrl"
                :disabled="$notifUrl === null"
            />
            @if ($count > 0)
                <span class="pointer-events-none absolute -top-1 -right-1 min-w-5 rounded-full border-2 border-white bg-primary px-1 text-center text-[11px] leading-4 font-bold text-white" aria-hidden="true">{{ $count > 99 ? '99+' : $count }}</span>
            @endif
        </span>

        <div class="relative" data-sth-menu>
            <button type="button" class="flex rounded-full" aria-expanded="false" aria-controls="sth-user-menu" data-sth-menu-button>
                <x-sth.avatar :name="$user['name']" size="lg" />
                <span class="sr-only">Akun {{ $user['name'] }}</span>
            </button>
            <div id="sth-user-menu" class="menu" hidden data-sth-menu-panel>
                <div class="mb-1.5 border-b border-line px-3 pt-2 pb-2.5">
                    <p class="m-0 text-sm font-bold">{{ $user['name'] }}</p>
                    <p class="m-0 text-[12.5px] text-muted">{{ $user['meta'] }}</p>
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
