{{--
    Account pages (Masuk board). Two variants:
    - split (default): ink brand panel (.spanduk, lintasan motif) with three straight sample cards on the
      left (lg+), form column on the right (max 400px) with "Lihat prestasi sekolah" top-right. Below lg: brand on top, form only.
    - simple: brand on top, one card (max 440px), centred on the screen from lg — lupa kata sandi, keamanan, desktop aktivasi.
    The page brings its own <h1> and form; use <x-sth.input>, <x-sth.button size="lg" block>, <x-sth.alert>.

    <x-layouts::auth title="Masuk">
        <h1 class="title-page lg:text-4xl">Masuk</h1> …
    </x-layouts::auth>

    Props: title · variant split | simple · robots (default 'noindex')
--}}
@props(['title' => null, 'variant' => 'split', 'robots' => 'noindex'])
@php
    $publicHome = \Illuminate\Support\Facades\Route::has('public.home') ? route('public.home') : url('/');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    @include('layouts.partials.head', ['title' => $title, 'robots' => $robots])
</head>
<body class="page min-h-dvh">
    <a href="#konten" class="skip-link">Lewati ke konten</a>

    @if ($variant === 'simple')
        <div class="flex min-h-dvh flex-col items-center px-4 py-8 sm:py-12 lg:justify-center">
            <x-sth.brand :href="$publicHome" />
            <main id="konten" tabindex="-1" class="card mt-8 w-full max-w-[440px] p-6 outline-none sm:p-8">
                {{ $slot }}
            </main>
        </div>
    @else
        <div class="flex min-h-dvh">
            <div class="hidden w-[560px] shrink-0 flex-col py-6 pl-6 lg:flex" aria-hidden="true">
                <div class="spanduk spanduk-tinggi flex-1 rounded-card p-10">
                    <x-sth.brand inverse />
                    <p class="display mt-16 max-w-[380px] text-5xl font-bold tracking-[-0.02em] text-white">Prestasimu, tercatat rapi.</p>
                    <p class="mt-3.5 max-w-[360px] text-base leading-[1.55] text-on-primary-soft">Daftar lomba, pantau surat tugas, dan kumpulkan poin prestasi.</p>

                    {{-- Three sample cards on one grid, straight (fictional demo data): a lomba, points, a letter. --}}
                    <div class="absolute inset-x-10 bottom-[176px] grid grid-cols-[240px_minmax(0,1fr)] items-end gap-4 [@media(max-height:760px)]:hidden">
                        <div class="row-span-2 overflow-hidden rounded-card bg-white text-ink shadow-float">
                            <x-sth.sampul bidang="riset" seed="lpb-2026" class="h-[92px] items-start p-3">
                                <span class="chip c-glass chip-sm">Nasional</span>
                            </x-sth.sampul>
                            <div class="flex flex-col gap-[7px] px-3.5 pt-3 pb-3.5">
                                <span class="chip b-riset chip-sm self-start">Riset</span>
                                <span class="display text-base leading-[1.25] font-bold">LPB 2026 · Nasional</span>
                                <span class="flex items-center gap-1.5 text-xs text-muted"><x-sth.icon name="calendar" :size="15" />17 Okt 2026 · Daring</span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2.5 rounded-card bg-white p-3.5 text-ink shadow-float">
                            <div class="flex items-center gap-2.5">
                                <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-tile bg-medal text-ink"><x-sth.icon name="award" /></span>
                                <span class="chip c-gold num h-7 text-sm">+68 poin</span>
                            </div>
                            <span class="text-xs text-muted">OPSI 2026 · Juara II</span>
                        </div>
                        <div class="flex flex-col gap-3 rounded-card bg-white p-3.5 text-ink shadow-float">
                            <div class="flex items-center gap-2.5">
                                <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-control bg-green-bg text-green"><x-sth.icon name="mail" :size="18" /></span>
                                <span class="flex flex-col">
                                    <span class="text-sm leading-[1.3] font-bold">Surat tugas terbit</span>
                                    <span class="text-xs text-muted">WICE 2026</span>
                                </span>
                            </div>
                            <x-sth.tracker :phase="4" compact class="[&_p]:hidden" />
                        </div>
                    </div>
                </div>
            </div>

            {{-- Below lg the brand bar is part of the flow (it wraps on narrow phones instead of covering the
                 heading, W1-C note ~420 px); from lg it floats top-right over the centred form. --}}
            <div class="relative flex min-w-0 flex-1 flex-col items-center px-4 pt-4 pb-10 sm:px-6 sm:pt-7 lg:justify-center lg:py-20">
                <div class="flex w-full flex-wrap items-center justify-between gap-x-3 gap-y-1 lg:absolute lg:top-7 lg:right-10 lg:w-auto">
                    <x-sth.brand size="sm" :href="$publicHome" class="lg:hidden" />
                    <a class="lnk ml-auto text-md font-semibold" href="{{ $publicHome }}">Lihat prestasi sekolah<x-sth.icon name="arrow-right" :size="18" /></a>
                </div>
                <main id="konten" tabindex="-1" class="my-auto flex w-full max-w-[400px] flex-col gap-6 pt-10 outline-none lg:pt-0">
                    {{ $slot }}
                </main>
            </div>
        </div>
    @endif

    <x-sth.flash />
    @livewireScripts
</body>
</html>
