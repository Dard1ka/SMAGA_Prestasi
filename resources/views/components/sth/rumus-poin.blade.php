{{--
    Points formula tiles: Tingkat × Capaian × Penyelenggara = gold result (DESIGN-SPEC-v2 §4), with
    decimal comma (80 · 0,85 · 1,00 · 68). Display only: `total` comes from the server
    (App\Domain\Points), Blade never calculates points.

    <x-sth.rumus-poin :tingkat="80" :capaian="0.85" :penyelenggara="1" :total="68" />
    <x-sth.rumus-poin :tingkat="100" capaian="1.00" penyelenggara="0.40" :total="$preview" result-label="Total" />

    Props: tingkat · capaian · penyelenggara (numbers or numeric strings; strings that are not numeric are
           shown as given) · total (null → "–") · result-label ("Hasil") · label (aria-label of the group)
--}}
@props(['tingkat', 'capaian', 'penyelenggara', 'total' => null, 'resultLabel' => 'Hasil', 'label' => 'Rumus poin'])
@php
    // One formatting source for points in the whole app: App\Domain\Points\PointFormatter (W1-B).
    $formatter = app(\App\Domain\Points\PointFormatter::class);
    $fmt = function ($value, bool $weight, int $decimals) use ($formatter): string {
        if ($value === null || $value === '') {
            return '–';
        }
        // Not is_numeric(): it accepts "0.", ".5", "+5" and "1e5", which the formatter then rejects with
        // an exception. A half-typed weight on Pengaturan > Bobot arrives here, so ask Decimal itself.
        if (! \App\Domain\Support\Decimal::isValid((string) $value)) {
            return (string) $value;
        }

        return $weight ? $formatter->formatWeight($value, $decimals) : $formatter->format($value, $decimals);
    };
    $t = $fmt($tingkat, false, 0);
    $c = $fmt($capaian, true, 2);
    $p = $fmt($penyelenggara, true, 2);
    $r = $fmt($total, false, 1);
@endphp
{{-- Phones: factors in one grid row, then "=" + a full-width gold row (Lapor-Hasil-Mobile). From 640px: one inline row. --}}
<div role="group" aria-label="{{ $label }}: {{ $t }} × {{ $c }} × {{ $p }} = {{ $r }} poin" {{ $attributes->class('flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-2.5') }}>
    <div class="grid grid-cols-[minmax(0,1fr)_12px_minmax(0,1fr)_12px_minmax(0,1.4fr)] items-center gap-1 sm:flex sm:gap-2.5" aria-hidden="true">
        <div class="rt px-[11px] sm:px-3.5"><span class="rt-l">Tingkat</span><span class="rt-v">{{ $t }}</span></div>
        <span class="rt-op text-center">×</span>
        <div class="rt px-[11px] sm:px-3.5"><span class="rt-l">Capaian</span><span class="rt-v">{{ $c }}</span></div>
        <span class="rt-op text-center">×</span>
        <div class="rt px-[11px] sm:px-3.5"><span class="rt-l">Penyelenggara</span><span class="rt-v">{{ $p }}</span></div>
    </div>
    <div class="grid grid-cols-[12px_minmax(0,1fr)] items-center gap-1 sm:flex sm:gap-2.5" aria-hidden="true">
        <span class="rt-op text-center">=</span>
        <div class="rt rt-gold flex-row items-center justify-between gap-2.5 px-3.5 py-3 sm:flex-col sm:items-start sm:justify-start sm:gap-0.5 sm:py-2.5">
            <span class="rt-l text-[13px] sm:text-xs">{{ $resultLabel }}</span>
            <span class="rt-v text-2xl leading-7 sm:text-xl sm:leading-6">{{ $r }} <span class="font-sans text-sm font-bold sm:text-[13px]">poin</span></span>
        </div>
    </div>
</div>
