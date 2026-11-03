{{--
    Data table (.tbl): head row on #F8F9FD, row dividers, hover, horizontal scroll on small screens.
    Headers are <th scope="col">; the caption is required (sr-only unless caption-visible).

    <x-sth.table caption="Katalog lomba SMAGA, 8 lomba">
        <x-slot:head>
            <th scope="col">Lomba</th>
            <th scope="col" class="w-32">Bidang</th>
            <th scope="col" class="w-26"><span class="sr-only">Aksi</span></th>
        </x-slot:head>
        @foreach ($lomba as $item)
            <tr wire:key="{{ $item->id }}"><td>…</td></tr>
        @endforeach
    </x-sth.table>

    Props: caption · caption-visible · card (wrap in .card, default true) · fixed (table-layout: fixed)
           table-class (classes for the <table>; a fixed table needs a floor such as "min-w-[720px]": below it the
           table scrolls sideways inside its card instead of squeezing a column to nothing on phones)
    Slots: head (cells of the header row) · default (body rows) · foot (optional <tr>s)
    Empty lists: render <x-sth.empty-state> instead of an empty table.
--}}
@props(['caption', 'captionVisible' => false, 'card' => true, 'fixed' => false, 'tableClass' => null])
<div {{ $attributes->class(['card overflow-hidden' => $card]) }}>
    <div class="tbl-wrap">
        <table @class(['tbl', 'table-fixed' => $fixed, $tableClass])>
            <caption @class(['tbl-caption' => $captionVisible, 'sr-only' => ! $captionVisible])>{{ $caption }}</caption>
            @isset($head)
                <thead>
                    <tr>{{ $head }}</tr>
                </thead>
            @endisset
            <tbody>
                {{ $slot }}
            </tbody>
            @isset($foot)
                <tfoot>{{ $foot }}</tfoot>
            @endisset
        </table>
    </div>
</div>
