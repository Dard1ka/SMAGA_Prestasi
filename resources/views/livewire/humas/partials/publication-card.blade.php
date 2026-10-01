{{-- One card of the "Siap publikasi" / "Terjadwal" lanes. $card comes from Publikasi::card(). --}}
<article wire:key="card-{{ $card['id'] }}" @class([
    'card relative overflow-hidden has-[button:focus-visible]:outline-3 has-[button:focus-visible]:outline-offset-2 has-[button:focus-visible]:outline-focus',
    'border-2 border-primary ring-4 ring-primary-tint' => $card['selected'],
])>
    <div class="relative h-14 px-3 py-2.5 {{ $card['cover'] }}">
        <x-sth.chip tone="gold" size="sm">{{ $card['headline'] }}</x-sth.chip>
        @if ($card['icon'])
            <x-sth.icon :name="$card['icon']" :size="30" class="absolute right-3 bottom-2 opacity-70" />
        @endif
    </div>
    <div class="flex flex-col gap-1 px-3 pt-3 pb-3.5">
        <h3 class="m-0 text-base leading-tight font-bold">
            <button type="button" wire:click="select('{{ $card['id'] }}')" class="cursor-pointer text-left outline-none after:absolute after:inset-0 after:content-['']" @if ($card['selected']) aria-current="true" @endif>
                {{ $card['title'] }}@if ($card['selected'])<span class="sr-only"> (dipilih)</span>@endif
            </button>
        </h3>
        <p class="m-0 text-[13.5px] leading-snug font-semibold">
            {{ $card['student'] }}@if ($card['class'])<span class="font-medium text-muted"> · {{ $card['class'] }}</span>@endif
        </p>
        <p class="m-0 text-[12.5px] leading-snug text-muted">{{ $card['mentor'] }}</p>
        @if ($card['when'])
            <p class="m-0 mt-0.5 flex items-start gap-1.5 text-[12.5px] leading-snug font-semibold text-primary-on-tint">
                <x-sth.icon name="calendar" :size="14" class="mt-px" /><span>{{ $card['when'] }}</span>
            </p>
        @endif
        <div class="mt-1.5 flex flex-wrap items-center gap-2">
            @if ($card['photo'])
                <x-sth.chip tone="green" size="sm">Izin foto ada</x-sth.chip>
            @else
                <x-sth.chip tone="gray" size="sm">Tanpa foto</x-sth.chip>
            @endif
        </div>
    </div>
</article>
