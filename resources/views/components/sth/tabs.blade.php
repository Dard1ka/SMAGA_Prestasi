{{--
    Tabs in three modes. Pick the one that matches where the state lives.

    1. Links (server state, e.g. ?tab=): items with `href` → <nav> of links, current = aria-current="page".
       <x-sth.tabs label="Daftar lomba" variant="pill" :active="$tab" :items="[
           ['key' => 'katalog', 'label' => 'Katalog', 'count' => 8, 'href' => route('kesiswaan.lomba.index')],
           ['key' => 'verifikasi', 'label' => 'Usulan lomba', 'count' => 3, 'href' => route('kesiswaan.lomba.index', ['tab' => 'verifikasi'])],
       ]" />

    2. Livewire: items with `attributes` → role="tab" buttons, aria-selected from `active`.
       :items="[['key' => 'tentang', 'label' => 'Tentang', 'attributes' => ['wire:click' => "\$set('tab', 'tentang')"]], …]"

    3. Client panels (no server round trip): `panels` + <x-sth.tab-panel> with matching keys; resources/js
       switches panels and supports ←/→/Home/End.
       <x-sth.tabs panels id="detail" label="Detail lomba" active="tentang" :items="[['key' => 'tentang', 'label' => 'Tentang'], …]" />
       <x-sth.tab-panel tabs="detail" name="tentang" active>…</x-sth.tab-panel>

    Props: items (key, label, count?, href? | attributes?) · active (key) · label (aria-label)
           variant underline (Detail lomba, default) | pill (Kelola lomba) · panels · id (needed with panels)
--}}
@props(['items' => [], 'active' => null, 'label' => null, 'variant' => 'underline', 'panels' => false, 'id' => null])
@php
    $tabClass = $variant === 'pill' ? 'tabp' : 'stab';
    $isLinks = ! $panels && collect($items)->contains(fn ($item) => isset($item['href']));
    $id ??= 'tabs-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));
    $wrapper = $variant === 'pill' ? 'flex flex-wrap gap-1.5' : 'flex gap-7 overflow-x-auto border-b border-line';
@endphp
@if ($isLinks)
    <nav @if ($label) aria-label="{{ $label }}" @endif {{ $attributes->class($wrapper) }}>
        @foreach ($items as $item)
            @php $isActive = (string) $item['key'] === (string) $active; @endphp
            <a href="{{ $item['href'] ?? '#' }}" class="{{ $tabClass }}" @if ($isActive) aria-current="page" @endif>
                {{ $item['label'] }}
                @isset($item['count'])<span class="cnt">{{ $item['count'] }}</span>@endisset
            </a>
        @endforeach
    </nav>
@else
    <div role="tablist" @if ($label) aria-label="{{ $label }}" @endif @if ($panels) data-sth-tabs="{{ $id }}" @endif {{ $attributes->class($wrapper) }}>
        @foreach ($items as $item)
            @php
                $isActive = (string) $item['key'] === (string) $active;
                $extra = new \Illuminate\View\ComponentAttributeBag($item['attributes'] ?? []);
            @endphp
            <button
                type="button"
                role="tab"
                id="{{ $id }}-tab-{{ $item['key'] }}"
                class="{{ $tabClass }}"
                aria-selected="{{ $isActive ? 'true' : 'false' }}"
                tabindex="{{ $isActive ? '0' : '-1' }}"
                @if ($panels) aria-controls="{{ $id }}-panel-{{ $item['key'] }}" data-sth-tab="{{ $item['key'] }}" @endif
                {{ $extra }}
            >
                {{ $item['label'] }}
                @isset($item['count'])<span class="cnt">{{ $item['count'] }}</span>@endisset
            </button>
        @endforeach
    </div>
@endif
