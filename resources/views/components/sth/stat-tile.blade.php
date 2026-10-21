{{--
    Stat tile (DESIGN-SPEC-v3 §4): 40px tinted icon tile + label / big value / note. On phones the tiles
    sit two per row (the caller's grid: grid-cols-2 … xl:grid-cols-4) with the icon above the text.

    <x-sth.stat-tile icon="trophy" tone="blue" label="Lomba aktif" value="6" note="pendaftaran dibuka" />
    <x-sth.stat-tile icon="inbox" tone="orange" label="Usulan lomba" :value="$pending" note="menunggu ditinjau"
                     href="{{ route('kesiswaan.lomba.index', ['tab' => 'verifikasi']) }}" />
    <x-sth.stat-tile icon="lock" tone="red" label="Dibekukan" :value="$frozen" zero="hide" />

    Props: icon · tone blue|green|orange|red|gray|gold|sains|riset|seni|olahraga (match the meaning)
           label · value (already formatted, e.g. Number::format) · note · href (whole tile becomes a link)
           layout  tile (default: icon above the text on phones, beside it from sm) | row (UX2: one compact line
                 at every width, icon · label + note · the value on the right; for tiles stacked in a column next
                 to other boxes, Status aktivasi)
           zero  what a value of 0 looks like: quiet (default: gray icon, muted number, so a colour only
                 shows where there is something to see) | hide (not rendered at all: for warning tiles such
                 as "Dibekukan", "Ditolak" that only matter when they are not 0) | show (like any value)
--}}
@props(['icon' => 'bars', 'tone' => 'blue', 'label', 'value', 'note' => null, 'href' => null, 'zero' => 'quiet', 'layout' => 'tile'])
@php
    $tones = [
        'blue' => 'bg-blue-bg text-blue',
        'green' => 'bg-green-bg text-green',
        'orange' => 'bg-orange-bg text-orange',
        'red' => 'bg-red-bg text-red',
        'gray' => 'bg-gray-bg text-gray',
        'gold' => 'bg-gold-bg text-gold',
        'sains' => 'bg-sains-bg text-sains',
        'riset' => 'bg-riset-bg text-riset',
        'seni' => 'bg-seni-bg text-seni',
        'olahraga' => 'bg-olahraga-bg text-olahraga',
    ];
    $isZero = in_array(trim((string) $value), ['0', '0,0', '0,00'], true);
    $quiet = $isZero && $zero === 'quiet';
    $toneClass = $quiet ? 'bg-gray-bg text-muted' : ($tones[$tone] ?? $tones['blue']);
    $tag = $href !== null ? 'a' : 'div';
@endphp
@if (! ($isZero && $zero === 'hide') && $layout === 'row')
    <{{ $tag }} @if ($href !== null) href="{{ $href }}" @endif {{ $attributes->class(['card flex items-center gap-3 px-4 py-3', 'text-ink no-underline hover:border-line-strong hover:text-ink' => $href !== null]) }}>
        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-tile {{ $toneClass }}">
            <x-sth.icon :name="$icon" />
        </span>
        <span class="flex min-w-0 flex-1 flex-col">
            <span class="text-sm leading-snug font-semibold text-ink">{{ $label }}</span>
            @if ($note)
                <span class="muted text-xs leading-snug">{{ $note }}</span>
            @endif
        </span>
        <span @class(['display num shrink-0 text-2xl leading-none font-bold', 'text-ink' => ! $quiet, 'text-muted' => $quiet])>{{ $value }}</span>
    </{{ $tag }}>
@elseif (! ($isZero && $zero === 'hide'))
    <{{ $tag }} @if ($href !== null) href="{{ $href }}" @endif {{ $attributes->class(['card flex flex-col items-start gap-2 p-3.5 sm:flex-row sm:items-center sm:gap-3.5 sm:p-[18px]', 'text-ink no-underline hover:border-line-strong hover:text-ink' => $href !== null]) }}>
        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-tile sm:size-10 {{ $toneClass }}">
            <x-sth.icon :name="$icon" />
        </span>
        <span class="flex min-w-0 flex-col gap-0.5">
            <span class="text-sm leading-snug font-medium text-ink-2">{{ $label }}</span>
            <span @class(['display num text-2xl leading-[1.1] font-bold sm:text-3xl', 'text-ink' => ! $quiet, 'text-muted' => $quiet])>{{ $value }}</span>
            @if ($note)
                <span class="muted text-xs leading-snug">{{ $note }}</span>
            @endif
        </span>
    </{{ $tag }}>
@endif
