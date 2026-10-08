{{--
    Head of a lomba page (Detail lomba, Undangan tim): the poster stands on the left (whole, at its own
    proportions; else the deep template drawing of the bidang), beside it what the caller puts in the slot
    (tags, title, penyelenggara, who registered) and then the key facts. On a narrow card (a phone) the poster
    is small and the facts run under it, across the whole width.

    <x-sth.lomba-head :bidang="$bidangKey" :seed="$competition->getKey()" :poster="$poster" :facts="$facts"
                      alt="Poster {{ $competition->name }}" aria-labelledby="h-lomba">
        <h1 id="h-lomba" class="title-page m-0 text-xl @min-[460px]:text-2xl @min-[640px]:text-3xl">{{ $competition->name }}</h1> …
    </x-sth.lomba-head>

    Props: bidang · seed (the lomba id: picks the template drawing) · facts (items of <x-sth.facts>) ·
           facts-label · alt (alt text of the poster)
           poster: null | the address of the poster (App\Support\Sampul::poster()) | an array
                   [src, href (download), preview (inline address), previewType, name]: with `preview` the poster
                   and "Perbesar" under it open the whole file in the preview popup (<x-sth.preview>)
    The card measures itself (container query): under 460px the poster is 104px wide, then 168px, from 640px
    216px. Give the title the three sizes of the example (20, 24, 28px), so it follows the card, not the window.
--}}
@props(['bidang' => null, 'seed' => null, 'poster' => null, 'facts' => [], 'factsLabel' => 'Info utama', 'alt' => ''])
@php
    $src = is_array($poster) ? ($poster['src'] ?? null) : $poster;
    $src = is_string($src) && $src !== '' ? $src : null;
    $href = is_array($poster) && $src !== null ? ($poster['href'] ?? null) : null;
    $preview = is_array($poster) ? ($poster['preview'] ?? null) : null;
@endphp
<section {{ $attributes->class('card @container p-4 sm:p-5') }}>
    <div class="grid grid-cols-[104px_minmax(0,1fr)] items-start gap-x-3.5 gap-y-4 @min-[460px]:grid-cols-[168px_minmax(0,1fr)] @min-[460px]:gap-x-5 @min-[640px]:grid-cols-[216px_minmax(0,1fr)] @min-[640px]:gap-x-6">
        <div class="min-w-0 @min-[460px]:row-span-2">
            @if ($href)
                {{-- With a preview the click opens the popup (<x-sth.preview>); the href stays the download. --}}
                <a href="{{ $href }}" class="group flex flex-col items-center gap-1.5 rounded-tile text-link no-underline"
                    @if ($preview)
                        data-sth-preview="{{ $preview }}"
                        data-sth-preview-type="{{ $poster['previewType'] ?? '' }}"
                        data-sth-preview-name="{{ $poster['name'] ?? '' }}"
                        aria-haspopup="dialog"
                    @endif>
                    <x-sth.sampul :bidang="$bidang" :seed="$seed" :src="$src" bentuk="utuh" tone="pekat" :alt="$alt" class="w-full rounded-tile border border-line" />
                    <span class="inline-flex min-h-9 items-center gap-1.5 text-sm font-semibold group-hover:underline">
                        <x-sth.icon name="image" :size="16" class="hidden @min-[460px]:block" />{{ $preview ? 'Perbesar' : 'Download' }}<span class="sr-only"> poster</span>
                    </span>
                </a>
            @else
                {{-- A poster may have a white edge: a hairline keeps it apart from the card. A drawing is coloured. --}}
                <x-sth.sampul :bidang="$bidang" :seed="$seed" :src="$src" bentuk="utuh" tone="pekat" :alt="$alt"
                              @class(['w-full rounded-tile', 'border border-line' => $src !== null]) />
            @endif
        </div>

        {{-- overflow-wrap is inherited by the caller's title and penyelenggara: a long word wraps (QA7). --}}
        <div class="flex min-w-0 flex-col gap-2.5 [overflow-wrap:anywhere]">
            {{ $slot }}
        </div>

        @if ($facts !== [])
            <x-sth.facts :items="$facts" :label="$factsLabel" plain
                         class="col-span-2 border-t border-line pt-4 @min-[460px]:col-span-1 @min-[460px]:col-start-2" />
        @endif
    </div>
</section>
