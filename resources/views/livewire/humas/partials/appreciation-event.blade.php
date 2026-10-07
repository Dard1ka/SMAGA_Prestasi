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

    @if ($event['adding'])
        <form wire:submit="addAchievement" class="flex flex-col gap-2 border-t border-line pt-3" novalidate>
            @if ($achievementOptions !== [])
                <x-sth.select wire:model="achievementId" label="Prestasi" placeholder="Pilih prestasi terverifikasi" :options="$achievementOptions" hint="Hanya prestasi siswa yang punya izin publikasi dan belum masuk acara mana pun." />
                <div class="flex flex-wrap gap-2">
                    <x-sth.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="addAchievement">Masukkan</x-sth.button>
                    <x-sth.button variant="quiet" size="sm" wire:click="cancelAdding">Batal</x-sth.button>
                </div>
            @else
                <p class="m-0 text-xs text-muted">Tidak ada prestasi lain yang bisa dimasukkan. Prestasi yang sudah dijadwalkan di acara lain perlu dilepas dulu dari acara itu.</p>
                <div><x-sth.button variant="quiet" size="sm" wire:click="cancelAdding">Tutup</x-sth.button></div>
            @endif
        </form>
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
