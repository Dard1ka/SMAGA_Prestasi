{{--
    Focused single-task flow, mobile first (Lapor-Hasil-Mobile, Aktivasi boards): back button + title
    + subtitle, one column (max 480px, centred on desktop), optional sticky bottom action bar and the
    siswa tab bar. No sidebar. Carries the file preview popup (<x-sth.preview>) like layouts::app.

    <x-layouts::mobile title="Laporkan hasil" subtitle="WICE 2026 · Riset · Tim 3 orang"
                       :back="route('siswa.lomba-saya.index')" back-label="Kembali ke Lomba saya">
        …cards…
        <x-slot:action>
            <x-sth.button type="submit" form="lapor-form" size="lg" block>Kirim laporan</x-sth.button>
        </x-slot:action>
    </x-layouts::mobile>

    Props: title (h1 24px) · subtitle · back (URL; omit to hide) · back-label ("Kembali")
           tabbar (true = siswa tab bar from the current route; or an items array from Navigation::tabbar()) · robots
    Slots: default · header (replaces title block, e.g. a step progress bar) · action (sticky bottom bar)
--}}
@props(['title' => null, 'subtitle' => null, 'back' => null, 'backLabel' => 'Kembali', 'tabbar' => false, 'robots' => null])
<!DOCTYPE html>
<html lang="id">
<head>
    @include('layouts.partials.head', ['title' => $title, 'robots' => $robots])
</head>
<body class="page min-h-dvh">
    <a href="#konten" class="skip-link">Lewati ke konten</a>

    <main id="konten" tabindex="-1" @class([
        'mx-auto flex w-full max-w-[480px] flex-col gap-4 px-5 pt-[22px] outline-none',
        'pb-[calc(176px+env(safe-area-inset-bottom))]' => isset($action) && $tabbar,
        'pb-[calc(112px+env(safe-area-inset-bottom))]' => isset($action) xor $tabbar,
        'pb-10' => ! isset($action) && ! $tabbar,
    ])>
        <header class="flex min-h-11 items-center gap-3">
            @if ($back)
                <x-sth.icon-button icon="chevron-left" :label="$backLabel" :href="$back" class="size-11 rounded-tile" />
            @endif
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                @isset($header)
                    {{ $header }}
                @else
                    @if ($title)
                        <h1 class="m-0 text-2xl leading-[30px] font-bold">{{ $title }}</h1>
                    @endif
                    @if ($subtitle)
                        <p class="m-0 text-sm leading-5 text-muted">{{ $subtitle }}</p>
                    @endif
                @endisset
            </div>
        </header>

        {{ $slot }}
    </main>

    @isset($action)
        <div @class([
            'fixed inset-x-0 z-30 border-t border-line bg-surface px-5 pt-3.5 shadow-[0_-12px_24px_-20px_rgb(20_24_60/0.35)]',
            'bottom-[calc(80px+env(safe-area-inset-bottom))] pb-3.5 lg:bottom-0' => $tabbar,
            'bottom-0 pb-[calc(18px+env(safe-area-inset-bottom))]' => ! $tabbar,
        ])>
            <div class="mx-auto flex w-full max-w-[440px] flex-col gap-2">{{ $action }}</div>
        </div>
    @endisset

    @if ($tabbar)
        <x-sth.tabbar :items="is_array($tabbar) ? $tabbar : null" />
    @endif

    <x-sth.preview />
    <x-sth.flash />
    @livewireScripts
</body>
</html>
