{{--
    White card (.card: border #E7E9F3, radius 20, soft shadow).

    <x-sth.card as="section" aria-labelledby="h-batas">…</x-sth.card>
    <x-sth.card tone="gold">Poin prestasi …</x-sth.card>
    <x-sth.card tone="warn" padding="sm">Perlu tindakan …</x-sth.card>
    <x-sth.card padding="none" class="overflow-hidden">cover + body</x-sth.card>

    Props: as div|section|article|aside|li · padding none | sm (16) | md (20, default) | lg (24)
           tone default | gold (points) | warn (needs-action wash)
--}}
@props(['as' => 'div', 'padding' => 'md', 'tone' => null])
@php
    $tag = in_array($as, ['div', 'section', 'article', 'aside', 'li'], true) ? $as : 'div';
    // match, not [...][$padding] ?? 'p-5': padding="none" maps to null and must stay without padding.
    $pad = match ($padding) {
        'none' => null,
        'sm' => 'p-4',
        'lg' => 'p-6',
        default => 'p-5',
    };
@endphp
<{{ $tag }} {{ $attributes->class(array_filter(['card', 'card-gold' => $tone === 'gold', 'card-warn' => $tone === 'warn', $pad])) }}>
    {{ $slot }}
</{{ $tag }}>
