{{--
    Catalog lomba card (DESIGN-SPEC-v2 §4): bidang cover with tingkat + status chips and a big line icon,
    then bidang/peserta chips, title (2 lines), penyelenggara, date + place, registered students, link.
    All texts arrive formatted (dates via Carbon translatedFormat, counts as ints).

    <x-sth.lomba-card
        title="National Science and Invention Fair (NSIF) 2026" href="{{ route('siswa.lomba.show', $c) }}"
        bidang="riset" tingkat="Nasional" status="Dibuka · 20 hari lagi" status-tone="green"
        peserta="Tim maks 3" penyelenggara="Penyelenggara swasta · Kategori D"
        date="7 Nov 2026" place="Daring" :names="['Raka Pradipta', 'Bima Satria Wicaksono', 'Yoga Pratama']" :count="3" />
    <x-sth.lomba-card … status="Segera dibuka" status-tone="gray" note="Daftar dibuka 1 Des 2026" />

    Props: title · href · bidang · tingkat · status · status-tone (green open | orange closing soon | gray
           closed/not yet) · peserta ("Individu", "Tim maks 3") · penyelenggara · date · place
           names (avatars) · count (registered SMAGA students) · note (replaces the avatar row)
           heading (h2|h3, default h3)
--}}
@props([
    'title',
    'href' => '#',
    'bidang' => 'sains',
    'tingkat' => null,
    'status' => null,
    'statusTone' => 'green',
    'peserta' => null,
    'penyelenggara' => null,
    'date' => null,
    'place' => null,
    'names' => [],
    'count' => null,
    'note' => null,
    'heading' => 'h3',
])
@php
    $enum = $bidang instanceof \App\Enums\Bidang
        ? $bidang
        : (is_string($bidang) ? \App\Enums\Bidang::tryFrom($bidang === 'seni' ? 'seni_budaya' : $bidang) : null);
    $key = $enum?->cssKey() ?? 'sains';
    $heading = in_array($heading, ['h2', 'h3', 'h4'], true) ? $heading : 'h3';
@endphp
<article {{ $attributes->class('card flex flex-col overflow-hidden') }}>
    <div class="cover-{{ $key }} relative flex h-32 items-start justify-between gap-2 p-3.5">
        @if ($tingkat)
            <x-sth.chip tone="glass">{{ $tingkat }}</x-sth.chip>
        @else
            <span></span>
        @endif
        @if ($status)
            <x-sth.chip :tone="$statusTone">{{ $status }}</x-sth.chip>
        @endif
        <x-sth.icon :name="$key" :size="56" class="absolute right-4 bottom-3 opacity-70" />
    </div>
    <div class="flex flex-1 flex-col gap-2.5 pt-4 pr-4 pb-3.5 pl-[18px]">
        <div class="flex flex-wrap gap-1.5">
            <x-sth.chip :bidang="$key" />
            @if ($peserta)
                <x-sth.chip tone="gray">{{ $peserta }}</x-sth.chip>
            @endif
        </div>
        <{{ $heading }} class="title-card clamp2">{{ $title }}</{{ $heading }}>
        @if ($penyelenggara)
            <p class="m-0 text-[13.5px] leading-[1.35] text-muted">{{ $penyelenggara }}</p>
        @endif
        @if ($date || $place)
            <div class="num flex flex-wrap items-center gap-x-3.5 gap-y-1 text-[13px] text-muted">
                @if ($date)
                    <span class="inline-flex items-center gap-1.5"><x-sth.icon name="calendar" :size="16" /><span class="sr-only">Tanggal lomba:</span>{{ $date }}</span>
                @endif
                @if ($place)
                    <span class="inline-flex items-center gap-1.5"><x-sth.icon name="pin" :size="16" /><span class="sr-only">Tempat:</span>{{ $place }}</span>
                @endif
            </div>
        @endif
        <div class="mt-auto flex items-center justify-between gap-2 border-t border-line pt-2">
            @if ($note)
                <span class="num inline-flex min-w-0 items-center gap-1.5 text-[12.5px] whitespace-nowrap text-muted"><x-sth.icon name="clock" :size="16" />{{ $note }}</span>
            @else
                <div class="flex min-w-0 items-center gap-2">
                    <x-sth.avatar-stack :names="$names" />
                    <span class="ellip text-[12.5px] text-muted">
                        @if ((int) $count > 0)
                            <span class="num">{{ $count }}</span> siswa SMAGA mendaftar
                        @else
                            Belum ada pendaftar
                        @endif
                    </span>
                </div>
            @endif
            <a class="lnk text-[13.5px]" href="{{ $href }}">Lihat detail<span class="sr-only"> {{ $title }}</span></a>
        </div>
    </div>
</article>
