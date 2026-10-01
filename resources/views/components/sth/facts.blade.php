{{--
    Key-facts strip (Detail lomba: Batas daftar · Tanggal lomba · Tingkat · Lokasi · Peserta).

    <x-sth.facts label="Info utama" :items="[
        ['icon' => 'clock', 'label' => 'Batas daftar', 'value' => '20 Okt 2026'],
        ['icon' => 'calendar', 'label' => 'Tanggal lomba', 'value' => '7 Nov 2026'],
        ['icon' => 'trophy', 'label' => 'Tingkat', 'value' => 'Nasional'],
        ['icon' => 'pin', 'label' => 'Lokasi', 'value' => 'Daring'],
        ['icon' => 'users', 'label' => 'Peserta', 'value' => 'Tim maks 3'],
    ]" />

    Props: items (icon, label, value) · label (aria-label of the section)
--}}
@props(['items' => [], 'label' => 'Info utama'])
@php
    $cols = [
        2 => 'grid-cols-2',
        3 => 'grid-cols-1 sm:grid-cols-3',
        4 => 'grid-cols-2 lg:grid-cols-4',
        5 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5',
        6 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-6',
    ][count($items)] ?? 'grid-cols-2 lg:grid-cols-4';
@endphp
<section aria-label="{{ $label }}" {{ $attributes->class('card') }}>
    <dl class="m-0 grid {{ $cols }}">
        @foreach ($items as $item)
            <div class="flex flex-col gap-0.5 border-line px-4 py-3.5 lg:border-l lg:first:border-l-0">
                <dt class="flex flex-col items-start gap-2 text-[12.5px] font-semibold text-muted">
                    <span class="inline-flex size-[34px] items-center justify-center rounded-[10px] bg-primary-tint text-primary" aria-hidden="true">
                        <x-sth.icon :name="$item['icon'] ?? 'info'" :size="18" />
                    </span>
                    {{ $item['label'] }}
                </dt>
                <dd class="num m-0 text-[15px] font-bold">{{ $item['value'] }}</dd>
            </div>
        @endforeach
    </dl>
</section>
