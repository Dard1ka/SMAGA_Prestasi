{{--
    Account pages (Masuk board). Two variants:
    - split (default): blue brand panel with floating preview cards on the left (lg+), form column on
      the right (max 400px) with "Lihat prestasi sekolah" top-right. Below lg: brand on top, form only.
    - simple: brand on top, one centred card (max 440px) — lupa kata sandi, keamanan, desktop aktivasi.
    The page brings its own <h1> and form; use <x-sth.input>, <x-sth.button size="lg" block>, <x-sth.alert>.

    <x-layouts::auth title="Masuk">
        <h1 class="title-page lg:text-[34px]">Masuk</h1> …
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
        <div class="flex min-h-dvh flex-col items-center px-4 py-8 sm:py-12">
            <x-sth.brand :href="$publicHome" />
            <main id="konten" tabindex="-1" class="card mt-8 w-full max-w-[440px] p-6 outline-none sm:p-8">
                {{ $slot }}
            </main>
        </div>
    @else
        <div class="flex min-h-dvh">
            <div class="hidden w-[560px] shrink-0 flex-col py-6 pl-6 lg:flex" aria-hidden="true">
                <div class="relative flex-1 overflow-hidden rounded-[28px] bg-primary p-10 text-white">
                    <span class="absolute -right-[150px] -bottom-[170px] size-[460px] rounded-full border-[1.5px] border-white/15"></span>
                    <span class="absolute -right-[60px] -bottom-[80px] size-[280px] rounded-full border-[1.5px] border-white/15"></span>
                    <span class="absolute top-10 right-11 size-32 rounded-full bg-white/[0.07]"></span>

                    <x-sth.brand inverse class="relative" />
                    <p class="display relative mt-16 max-w-[380px] text-[46px] leading-[1.05] font-extrabold tracking-[-0.02em] text-white">Prestasimu, tercatat rapi.</p>
                    <p class="relative mt-3.5 max-w-[360px] text-base leading-[1.55] text-on-primary-soft">Daftar lomba, pantau surat tugas, dan kumpulkan poin prestasi.</p>

                    <div class="[@media(max-height:760px)]:hidden">
                        <div class="absolute bottom-[180px] left-10 w-60 -rotate-4 overflow-hidden rounded-[18px] bg-white text-ink shadow-float">
                            <div class="cover-riset relative h-[92px] p-3">
                                <span class="chip c-glass chip-sm">Nasional</span>
                                <x-sth.icon name="riset" :size="44" class="absolute right-3.5 bottom-2.5 opacity-70" />
                            </div>
                            <div class="flex flex-col gap-[7px] px-3.5 pt-3 pb-3.5">
                                <span class="chip b-riset chip-sm self-start">Riset</span>
                                <span class="display text-base leading-[1.25] font-bold">LPB 2026 · Nasional</span>
                                <span class="flex items-center gap-1.5 text-[12.5px] text-muted"><x-sth.icon name="calendar" :size="15" />17 Okt 2026 · Daring</span>
                            </div>
                        </div>
                        <div class="absolute bottom-[330px] left-[302px] flex w-[190px] rotate-5 flex-col gap-2.5 rounded-[18px] bg-white p-3.5 text-ink shadow-float">
                            <div class="flex items-center gap-2.5">
                                <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-medal text-gold"><x-sth.icon name="award" /></span>
                                <span class="chip c-gold num h-7 text-sm">+68 poin</span>
                            </div>
                            <span class="text-[12.5px] text-muted">OPSI 2026 · Juara II</span>
                        </div>
                        <div class="absolute bottom-[110px] left-[268px] flex w-[228px] -rotate-2 flex-col gap-3 rounded-[18px] bg-white p-3.5 text-ink shadow-float">
                            <div class="flex items-center gap-2.5">
                                <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-control bg-green-bg text-green"><x-sth.icon name="mail" :size="18" /></span>
                                <span class="flex flex-col">
                                    <span class="text-sm leading-[1.3] font-bold">Surat tugas terbit</span>
                                    <span class="text-[12.5px] text-muted">WICE 2026</span>
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
                    <a class="lnk ml-auto text-[14.5px] font-semibold" href="{{ $publicHome }}">Lihat prestasi sekolah<x-sth.icon name="arrow-right" :size="18" /></a>
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
