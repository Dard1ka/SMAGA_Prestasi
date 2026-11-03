{{--
    Key facts of a lomba (Batas daftar · Tanggal lomba · Tingkat · Lokasi · Peserta).

    <x-sth.facts label="Info utama" :items="[
        ['icon' => 'clock', 'label' => 'Batas daftar', 'value' => '20 Okt 2026'],
        ['icon' => 'calendar', 'label' => 'Tanggal lomba', 'value' => '7 Nov 2026'],
        ['icon' => 'trophy', 'label' => 'Tingkat', 'value' => 'Nasional'],
        ['icon' => 'pin', 'label' => 'Lokasi', 'value' => 'Daring'],
        ['icon' => 'users', 'label' => 'Peserta', 'value' => 'Tim maks 3'],
    ]" />
    <x-sth.facts :items="$facts" plain />      inside another card (<x-sth.lomba-head>): no card of its own

    Props: items (icon, label, value) · label (aria-label of the section)
           plain (no card, no cell padding, no lines: a plain grid for use inside a card)
--}}
@props(['items' => [], 'label' => 'Info utama', 'plain' => false])
@php
    // The columns follow the width of the strip itself (container query), not the window: next to a side panel
    // on a 1280–1366px laptop the strip is about 550–630px wide, and five 110px cells cannot hold
    // "Kota/Kabupaten". One row only when every cell gets about 138px; the lines between cells show in that
    // one-row layout only. Plain: two per row, three from 400px (a cell without padding needs about 120px).
    [$cols, $line] = [
        2 => ['grid-cols-2', 'border-l first:border-l-0'],
        3 => ['grid-cols-1 @md:grid-cols-3', '@md:border-l @md:first:border-l-0'],
        4 => ['grid-cols-2 @min-[552px]:grid-cols-4', '@min-[552px]:border-l @min-[552px]:first:border-l-0'],
        5 => ['grid-cols-2 @md:grid-cols-3 @min-[690px]:grid-cols-5', '@min-[690px]:border-l @min-[690px]:first:border-l-0'],
        6 => ['grid-cols-2 @md:grid-cols-3 @min-[828px]:grid-cols-6', '@min-[828px]:border-l @min-[828px]:first:border-l-0'],
    ][count($items)] ?? ['grid-cols-2 @min-[552px]:grid-cols-4', '@min-[552px]:border-l @min-[552px]:first:border-l-0'];
    $plainCols = count($items) <= 2 ? 'grid-cols-2' : 'grid-cols-2 @min-[400px]:grid-cols-3';
@endphp
<section aria-label="{{ $label }}" {{ $attributes->class(['@container', 'card' => ! $plain]) }}>
    <dl @class(['m-0 grid', $cols => ! $plain, $plainCols.' gap-x-5 gap-y-3.5' => $plain])>
        @foreach ($items as $item)
            <div @class(['flex min-w-0 flex-col gap-0.5', 'border-line px-4 py-3.5 '.$line => ! $plain])>
                {{-- Icon next to the label, no tinted box: five boxes in a row said nothing the labels do not. --}}
                <dt class="flex items-center gap-1.5 text-xs font-semibold text-muted">
                    <x-sth.icon :name="$item['icon'] ?? 'info'" :size="16" />
                    {{ $item['label'] }}
                </dt>
                <dd class="num m-0 text-md font-semibold break-words text-ink">{{ $item['value'] }}</dd>
            </div>
        @endforeach
    </dl>
</section>
