{{--
    Catalog lomba card (DESIGN-SPEC-v3 §4): cover (the lomba's poster, else a template drawing of its bidang)
    with tingkat + status tags, then bidang/peserta tags, title (2 lines), penyelenggara, date + place,
    registered students, link. All texts arrive formatted (dates via Carbon translatedFormat, counts as ints).

    <x-sth.lomba-card
        title="National Science and Invention Fair (NSIF) 2026" href="{{ route('siswa.lomba.show', $c) }}"
        bidang="riset" :seed="$c->id" :poster="\App\Support\Sampul::poster($c)"
        tingkat="Nasional" status="Dibuka · 20 hari lagi" status-tone="green"
        peserta="Tim maks 3" penyelenggara="Penyelenggara swasta · Kategori D"
        date="7 Nov 2026" place="Daring" :names="['Raka Pradipta', 'Bima Satria Wicaksono', 'Yoga Pratama']" :count="3" />
    <x-sth.lomba-card … status="Segera dibuka" status-tone="gray" note="Daftar dibuka 1 Des 2026" />

    Props: title · href · bidang · tingkat · status · status-tone (green open | orange closing soon | gray
           closed/not yet) · peserta ("Individu", "Tim maks 3") · penyelenggara · date · place
           names (avatars) · count (registered SMAGA students) · note (replaces the avatar row)
           heading (h2|h3, default h3)
           seed (the lomba id: picks one of the three template drawings; App\Support\Sampul)
           poster (address of the uploaded poster or null; LombaPresenter::card() fills both)
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
    'seed' => null,
    'poster' => null,
])
@php
    $key = \App\Support\Sampul::key($bidang);
    $heading = in_array($heading, ['h2', 'h3', 'h4'], true) ? $heading : 'h3';
@endphp
<article {{ $attributes->class('card flex flex-col overflow-hidden') }}>
    <x-sth.sampul :bidang="$key" :seed="$seed ?? $title" :src="$poster" class="h-24 items-start justify-between gap-2 p-3 sm:h-[124px]">
        @if ($tingkat)
            <x-sth.chip tone="glass">{{ $tingkat }}</x-sth.chip>
        @else
            <span></span>
        @endif
        @if ($status)
            <x-sth.chip :tone="$statusTone">{{ $status }}</x-sth.chip>
        @endif
    </x-sth.sampul>
    <div class="flex flex-1 flex-col gap-2.5 px-4 pt-3.5 pb-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <x-sth.chip :bidang="$key" />
            @if ($peserta)
                <x-sth.chip tone="gray">{{ $peserta }}</x-sth.chip>
            @endif
        </div>
        <{{ $heading }} class="title-card clamp2">{{ $title }}</{{ $heading }}>
        @if ($penyelenggara)
            <p class="m-0 text-sm leading-[1.35] text-muted">{{ $penyelenggara }}</p>
        @endif
        @if ($date || $place)
            <div class="num flex flex-wrap items-center gap-x-3.5 gap-y-1 text-sm text-ink-2">
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
                <span class="num inline-flex min-w-0 items-center gap-1.5 text-xs whitespace-nowrap text-muted"><x-sth.icon name="clock" :size="16" />{{ $note }}</span>
            @else
                <div class="flex min-w-0 items-center gap-2">
                    <x-sth.avatar-stack :names="$names" />
                    <span class="ellip text-xs text-muted">
                        @if ((int) $count > 0)
                            <span class="num">{{ $count }}</span> siswa SMAGA mendaftar
                        @else
                            Belum ada pendaftar
                        @endif
                    </span>
                </div>
            @endif
            <a class="lnk" href="{{ $href }}">Lihat detail<span class="sr-only"> {{ $title }}</span></a>
        </div>
    </div>
</article>
