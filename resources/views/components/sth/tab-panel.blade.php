{{--
    Panel for <x-sth.tabs panels id="…">. Inactive panels are `hidden`; resources/js switches them.

    <x-sth.tab-panel tabs="detail" name="tentang" active>…</x-sth.tab-panel>
    <x-sth.tab-panel tabs="detail" name="jadwal">…</x-sth.tab-panel>

    Props: tabs (the tabs id) · name (= the item key) · active
--}}
@props(['tabs', 'name', 'active' => false])
<div
    role="tabpanel"
    id="{{ $tabs }}-panel-{{ $name }}"
    aria-labelledby="{{ $tabs }}-tab-{{ $name }}"
    tabindex="0"
    data-sth-panel="{{ $tabs }}"
    @unless ($active) hidden @endunless
    {{ $attributes }}
>
    {{ $slot }}
</div>
