{{-- One card of the "Siap publikasi" / "Terjadwal" lanes. $card comes from Publikasi::card(). --}}
<article wire:key="card-{{ $card['id'] }}" @class([
    'card relative overflow-hidden has-[button:focus-visible]:outline-3 has-[button:focus-visible]:outline-offset-2 has-[button:focus-visible]:outline-focus',
    'border-2 border-primary ring-4 ring-primary-tint' => $card['selected'],
])>
    {{-- A band of the bidang's template drawing; an achievement without a bidang gets a plain band. --}}
    @if ($card['bidang'])
        <x-sth.sampul :bidang="$card['bidang']" :seed="$card['seed']" class="h-14 items-start px-3 py-2.5">
            <x-sth.chip tone="gold" size="sm">{{ $card['headline'] }}</x-sth.chip>
        </x-sth.sampul>
    @else
        <div class="flex h-14 items-start bg-subtle px-3 py-2.5">
            <x-sth.chip tone="gold" size="sm">{{ $card['headline'] }}</x-sth.chip>
        </div>
    @endif
    <div class="flex flex-col gap-1 px-3 pt-3 pb-3.5 [overflow-wrap:anywhere]">
        <h3 class="m-0 text-base leading-tight font-bold">
            <button type="button" wire:click="select('{{ $card['id'] }}')" class="cursor-pointer text-left outline-none after:absolute after:inset-0 after:content-['']" @if ($card['selected']) aria-current="true" @endif>
                {{ $card['title'] }}@if ($card['selected'])<span class="sr-only"> (dipilih)</span>@endif
            </button>
        </h3>
        <p class="m-0 text-sm leading-snug font-semibold">
            {{ $card['student'] }}@if ($card['class'])<span class="font-medium text-muted"> · {{ $card['class'] }}</span>@endif
        </p>
        <p class="m-0 text-xs leading-snug text-muted">{{ $card['mentor'] }}</p>
        @if ($card['when'])
            <p class="m-0 mt-0.5 flex items-start gap-1.5 text-xs leading-snug font-semibold text-primary-on-tint">
                <x-sth.icon name="calendar" :size="14" class="mt-px" /><span>{{ $card['when'] }}</span>
            </p>
        @endif
        <div class="mt-1.5 flex flex-wrap items-center gap-2">
            {{-- D-122: the scheduled time passed, Humas has not pressed "Tandai dipublikasikan" yet. --}}
            @if ($card['overdue'])
                <x-sth.chip tone="orange" size="sm">Lewat jadwal</x-sth.chip>
            @endif
            @if ($card['photo'])
                <x-sth.chip tone="green" size="sm">Izin foto ada</x-sth.chip>
            @else
                <x-sth.chip tone="gray" size="sm">Tanpa foto</x-sth.chip>
            @endif
        </div>
    </div>
</article>
