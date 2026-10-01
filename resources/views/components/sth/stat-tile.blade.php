{{--
    Stat tile, CrioEd style (DESIGN-SPEC-v2 §4): 44px tinted icon tile + label / big value / note.

    <x-sth.stat-tile icon="trophy" tone="blue" label="Lomba aktif" value="6" note="pendaftaran dibuka" />
    <x-sth.stat-tile icon="inbox" tone="orange" label="Usulan lomba" :value="$pending" note="menunggu ditinjau"
                     href="{{ route('kesiswaan.lomba.index', ['tab' => 'verifikasi']) }}" />

    Props: icon · tone blue|green|orange|red|gray|gold|sains|riset|seni|olahraga (match the meaning)
           label · value (already formatted, e.g. Number::format) · note · href (whole tile becomes a link)
--}}
@props(['icon' => 'bars', 'tone' => 'blue', 'label', 'value', 'note' => null, 'href' => null])
@php
    $tones = [
        'blue' => 'bg-primary-tint text-primary',
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
    $toneClass = $tones[$tone] ?? $tones['blue'];
    $tag = $href !== null ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href !== null) href="{{ $href }}" @endif {{ $attributes->class(['card flex items-center gap-3.5 p-[18px]', 'text-ink no-underline hover:border-line-input hover:text-ink' => $href !== null]) }}>
    <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile {{ $toneClass }}">
        <x-sth.icon :name="$icon" />
    </span>
    <span class="flex min-w-0 flex-col gap-0.5">
        <span class="muted text-[13px] font-semibold leading-snug">{{ $label }}</span>
        <span class="display num text-[28px] leading-[1.1] font-extrabold text-ink">{{ $value }}</span>
        @if ($note)
            <span class="muted text-[12.5px] leading-snug">{{ $note }}</span>
        @endif
    </span>
</{{ $tag }}>
