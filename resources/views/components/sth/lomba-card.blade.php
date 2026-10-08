{{--
    Catalog lomba card, a "capsule" (DESIGN-SPEC-v3 §4, §5): the poster stands on the left (the lomba's own
    poster, else a template drawing of its bidang; always the same 3:4 box), the text beside it: status, title,
    bidang / tingkat / peserta tags, penyelenggara, date + place. Under both: registered students and the link.
    All texts arrive formatted (dates via Carbon translatedFormat, counts as ints).

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

    The card measures itself (container query), not the window: the poster is 104px wide on a card under 440px
    (a phone) and 148px from there. Put the cards in a grid that never makes them much narrower than a phone:
        <div class="@container …"><div class="grid grid-cols-1 gap-[18px] @min-[900px]:grid-cols-2 @min-[1360px]:grid-cols-3">…
    The whole card opens the lomba: the one link ("Lihat detail") is stretched over it.
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
<article {{ $attributes->class('card @container relative transition-colors hover:border-line-strong') }}>
    <div class="flex h-full flex-col gap-2.5 p-3 @min-[440px]:p-3.5">
        <div class="flex items-start gap-3 @min-[440px]:gap-4">
            <x-sth.sampul :bidang="$key" :seed="$seed ?? $title" :src="$poster" bentuk="tegak"
                          class="w-[104px] shrink-0 rounded-tile @min-[440px]:w-[148px]" />

            {{-- overflow-wrap is inherited: one long word typed by a person wraps here instead of widening the page (QA7). --}}
            <div class="flex min-w-0 flex-1 flex-col items-start gap-2 [overflow-wrap:anywhere]">
                @if ($status)
                    <x-sth.chip :tone="$statusTone">{{ $status }}</x-sth.chip>
                @endif
                <{{ $heading }} class="title-card line-clamp-3 text-base @min-[440px]:line-clamp-2 @min-[440px]:text-lg">{{ $title }}</{{ $heading }}>
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                    <x-sth.chip :bidang="$key" />
                    @if ($tingkat)
                        <x-sth.chip tone="gray">{{ $tingkat }}</x-sth.chip>
                    @endif
                    @if ($peserta)
                        <x-sth.chip tone="gray">{{ $peserta }}</x-sth.chip>
                    @endif
                </div>
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
            </div>
        </div>

        <div class="mt-auto flex items-center justify-between gap-2 border-t border-line pt-1.5">
            @if ($note)
                <span class="num inline-flex min-w-0 items-center gap-1.5 text-xs whitespace-nowrap text-muted"><x-sth.icon name="clock" :size="16" />{{ $note }}</span>
            @else
                <div class="flex min-w-0 items-center gap-2">
                    <x-sth.avatar-stack :names="$names" />
                    {{-- May take two lines on a 360px phone: never cut with "…". --}}
                    <span class="min-w-0 text-xs leading-[1.3] text-muted">
                        @if ((int) $count > 0)
                            <span class="num">{{ $count }}</span> siswa SMAGA mendaftar
                        @else
                            Belum ada pendaftar
                        @endif
                    </span>
                </div>
            @endif
            <a class="lnk shrink-0 after:absolute after:inset-0" href="{{ $href }}">Lihat detail<span class="sr-only"> {{ $title }}</span></a>
        </div>
    </div>
</article>
