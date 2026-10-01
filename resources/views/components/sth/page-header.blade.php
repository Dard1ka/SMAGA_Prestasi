{{--
    Heading row for a page or section: title (+ subtitle) on the left, actions on the right.
    In the app shell the page <h1> lives in the topbar (layout `title`), so inside pages use level 2
    for sections. Public pages use level 1.

    <x-sth.page-header title="Lomba saya">
        <x-slot:actions><x-sth.link href="…" icon-right="chevron-right">Lihat semua</x-sth.link></x-slot:actions>
    </x-sth.page-header>
    <x-sth.page-header title="Rekomendasi untukmu" subtitle="Sesuai bidang minatmu: Riset & Sains" />
    <x-sth.page-header :level="1" title="Prestasi per bidang" subtitle="Data terverifikasi sekolah."
        :breadcrumbs="[['label' => 'Cari lomba', 'href' => route('siswa.lomba.index')], ['label' => 'NSIF 2026']]" />

    Props: title · subtitle · level (1 = page title 30px, 2 = section 20px, 3) · id (heading id for
           aria-labelledby) · breadcrumbs (list of [label, href?]; the last one is the current page)
    Slot: actions
--}}
@props(['title', 'subtitle' => null, 'level' => 2, 'id' => null, 'breadcrumbs' => []])
@php
    $level = min(max((int) $level, 1), 3);
    $tag = 'h'.$level;
    $titleClass = [1 => 'title-page', 2 => 'title-section', 3 => 'title-card'][$level];
@endphp
<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-x-6 gap-y-2') }}>
    <div class="flex min-w-0 flex-col gap-1">
        @if ($breadcrumbs !== [])
            <nav aria-label="Breadcrumb">
                <ol class="m-0 flex list-none flex-wrap items-center gap-1.5 p-0 text-[14.5px]">
                    @foreach ($breadcrumbs as $crumb)
                        @if (! $loop->last)
                            <li>
                                <a class="lnk text-[14.5px]" href="{{ $crumb['href'] ?? '#' }}">
                                    @if ($loop->first)<x-sth.icon name="chevron-left" :size="18" />@endif{{ $crumb['label'] }}
                                </a>
                            </li>
                            <li class="inline-flex text-placeholder" aria-hidden="true"><x-sth.icon name="chevron-right" :size="16" /></li>
                        @else
                            <li aria-current="page" class="font-semibold text-ink-2">{{ $crumb['label'] }}</li>
                        @endif
                    @endforeach
                </ol>
            </nav>
        @endif
        @if ($level === 1)
            <{{ $tag }} @if ($id) id="{{ $id }}" @endif class="{{ $titleClass }}">{{ $title }}</{{ $tag }}>
            @if ($subtitle)
                <p class="m-0 text-[15px] text-muted">{{ $subtitle }}</p>
            @endif
        @else
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                <{{ $tag }} @if ($id) id="{{ $id }}" @endif class="{{ $titleClass }}">{{ $title }}</{{ $tag }}>
                @if ($subtitle)
                    <span class="text-[13.5px] text-muted">{{ $subtitle }}</span>
                @endif
            </div>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2.5">{{ $actions }}</div>
    @endisset
</div>
