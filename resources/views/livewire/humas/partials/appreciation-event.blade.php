{{-- One appreciation event card. $event comes from Apresiasi::event(). --}}
<x-sth.card as="article" padding="sm" wire:key="event-{{ $event['id'] }}" aria-labelledby="event-{{ $event['id'] }}-judul" class="flex flex-col gap-3">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <h3 id="event-{{ $event['id'] }}-judul" class="m-0 text-base leading-snug font-bold">{{ $event['name'] }}</h3>
            <p class="m-0 text-xs text-muted">{{ $event['date'] }} · <span class="num">{{ $event['count'] }}</span> prestasi</p>
            @if ($event['completedNote'])
                <p class="m-0 text-xs text-muted">{{ $event['completedNote'] }}</p>
            @endif
        </div>
        <x-sth.chip :tone="$event['chip'][0]" size="sm">{{ $event['chip'][1] }}</x-sth.chip>
    </div>

    @if ($event['items'] !== [])
        <ul class="m-0 flex list-none flex-col gap-1.5 p-0">
            @foreach ($event['items'] as $row)
                <li wire:key="apr-{{ $row['id'] }}" class="flex flex-wrap items-center justify-between gap-2 rounded-tile bg-subtle px-3 py-2">
                    <div class="flex min-w-0 flex-col">
                        <span class="text-sm font-semibold">{{ $row['student'] }}@if ($row['class'])<span class="font-medium text-muted"> · {{ $row['class'] }}</span>@endif</span>
                        <span class="text-xs text-muted">{{ $row['headline'] }} · {{ $row['competition'] }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @if ($row['consentWithdrawn'])
                            <x-sth.chip tone="orange" size="sm">Izin dicabut</x-sth.chip>
                        @endif
                        <x-sth.chip :tone="$row['status'][0]" size="sm">{{ $row['status'][1] }}</x-sth.chip>
                        @if ($row['canRelease'])
                            <x-sth.button variant="quiet" size="sm" aria-haspopup="dialog" wire:click="confirmRelease('{{ $row['id'] }}')">Lepas dari acara<span class="sr-only">: {{ $row['student'] }}, {{ $row['headline'] }}</span></x-sth.button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <p class="m-0 text-xs text-muted">Belum ada prestasi di acara ini.</p>
    @endif

    @if ($event['adding'] && $picker)
        {{--
            "Tambah prestasi" (D-124): search, tick, one "Masukkan" for everything ticked. $picker comes from
            Apresiasi::picker(). No <form>: Enter in the search box must not submit. The ticks are a deferred
            wire:model (no request per tick); they travel with the next search, "Tampilkan lebih banyak" or "Masukkan".
        --}}
        <div wire:key="tambah-{{ $event['id'] }}" class="flex flex-col gap-3 border-t border-line pt-3">
            @if ($picker['empty'])
                <p class="m-0 text-xs text-muted">Tidak ada prestasi lain yang bisa dimasukkan. Prestasi yang sudah dijadwalkan di acara lain perlu dilepas dulu dari acara itu.</p>
                <div><x-sth.button variant="quiet" size="sm" wire:click="cancelAdding">Tutup</x-sth.button></div>
            @else
                <x-sth.input type="search" wire:model.live.debounce.400ms="search" id="cari-prestasi" label="Cari prestasi" icon="search"
                    placeholder="Nama siswa atau nama lomba" maxlength="100" autocomplete="off"
                    hint="Hanya prestasi siswa yang punya izin publikasi dan belum masuk acara mana pun." />
                <p class="m-0 text-xs text-muted" role="status">{{ $picker['status'] }}</p>

                @if ($picker['kept'] !== [] || $picker['rows'] !== [])
                    {{-- Ticked rows the search no longer shows come first, so a tick never disappears. --}}
                    <ul aria-label="Prestasi yang bisa dimasukkan" class="m-0 flex list-none flex-col gap-0.5 p-0">
                        @foreach ([$picker['kept'], $picker['rows']] as $group)
                            @foreach ($group as $row)
                                <li wire:key="pilih-{{ $row['id'] }}">
                                    <label class="flex min-h-11 cursor-pointer items-start gap-2.5 rounded-tile px-2 py-2 hover:bg-hover">
                                        <input type="checkbox" class="cek mt-0.5" value="{{ $row['id'] }}" wire:model="picked">
                                        <span class="flex min-w-0 flex-col [overflow-wrap:anywhere]">
                                            <span class="text-sm font-semibold">{{ $row['student'] }}@if ($row['class'])<span class="font-medium text-muted"> · {{ $row['class'] }}</span>@endif</span>
                                            <span class="text-xs text-muted">{{ $row['headline'] }} · {{ $row['competition'] }}</span>
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                @endif

                @if ($picker['more'])
                    <div><x-sth.button variant="quiet" size="sm" wire:click="showMoreOptions" wire:loading.attr="disabled" wire:target="showMoreOptions">Tampilkan lebih banyak</x-sth.button></div>
                @elseif ($picker['capped'])
                    <p class="m-0 text-xs text-muted">Masih ada prestasi lain. Persempit dengan pencarian.</p>
                @endif

                @error('picked')
                    <p class="field-error" role="alert"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>
                @enderror

                <div class="flex flex-wrap gap-2">
                    <x-sth.button size="sm" wire:click="addAchievements" wire:loading.attr="disabled" wire:target="addAchievements">Masukkan</x-sth.button>
                    <x-sth.button variant="quiet" size="sm" wire:click="cancelAdding">Batal</x-sth.button>
                </div>
            @endif
        </div>
    @elseif ($event['canAdd'] || $event['canComplete'] || $event['canDelete'] || $event['completeNote'])
        <div class="flex flex-col gap-2 border-t border-line pt-3">
            @if ($event['canAdd'] || $event['canComplete'] || $event['canDelete'])
                <div class="flex flex-wrap gap-2">
                    @if ($event['canAdd'])
                        <x-sth.button variant="soft" size="sm" icon="plus" wire:click="startAdding('{{ $event['id'] }}')">Tambah prestasi</x-sth.button>
                    @endif
                    @if ($event['canComplete'])
                        <x-sth.button variant="soft" size="sm" icon="check" aria-haspopup="dialog" wire:click="confirmComplete('{{ $event['id'] }}')">Tandai sudah dilaksanakan</x-sth.button>
                    @endif
                    @if ($event['canDelete'])
                        <x-sth.button variant="quiet" size="sm" icon="trash" aria-haspopup="dialog" wire:click="confirmDelete('{{ $event['id'] }}')">Hapus acara</x-sth.button>
                    @endif
                </div>
            @endif
            @if ($event['completeNote'])
                <p class="m-0 text-xs text-muted">{{ $event['completeNote'] }}</p>
            @endif
        </div>
    @endif
</x-sth.card>
