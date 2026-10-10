{{-- Notifikasi (bell; UI-MAP §2). Own database notifications only, prepared in App\Livewire\Akun\Notifikasi.
     data-sth-refresh-on: app.js refreshes this component when a background check sees the bell count change, so a
     notification that arrives while the list is open shows by itself (D-127). --}}
<div class="flex flex-col gap-[22px]" data-sth-refresh-on="{{ \App\Support\Navigation::BELL }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="seg" role="group" aria-label="Tampilkan">
            <button type="button" class="seg-btn" wire:click="setFilter('semua')" aria-pressed="{{ $filter === 'semua' ? 'true' : 'false' }}">
                Semua <span class="cnt">{{ $totalCount }}</span>
            </button>
            <button type="button" class="seg-btn" wire:click="setFilter('belum')" aria-pressed="{{ $filter === 'belum' ? 'true' : 'false' }}">
                Belum dibaca <span class="cnt">{{ $unreadCount }}</span>
            </button>
        </div>
        @if ($unreadCount > 0)
            <x-sth.button variant="quiet" size="sm" icon="check" wire:click="markAllRead" wire:loading.attr="disabled">Tandai semua dibaca</x-sth.button>
        @endif
    </div>

    @if ($items->isEmpty())
        <x-sth.empty-state icon="bell" :title="$filter === 'belum' ? 'Tidak ada notifikasi baru' : 'Belum ada notifikasi'">
            Kabar tentang lomba, surat, dan akun muncul di sini.
        </x-sth.empty-state>
    @else
        <x-sth.card as="section" padding="none" class="overflow-hidden" aria-label="Daftar notifikasi">
            <ul class="flex flex-col divide-y divide-line">
                @foreach ($items as $item)
                    <li wire:key="notif-{{ $item['id'] }}" @class(['flex items-start gap-3 px-4 py-3.5 sm:px-5', 'bg-primary-wash' => $item['unread']])>
                        <span @class([
                            'mt-1.5 size-2.5 shrink-0 rounded-full',
                            'bg-primary' => $item['unread'],
                            'bg-transparent' => ! $item['unread'],
                        ]) aria-hidden="true"></span>
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <p class="m-0 font-bold">
                                {{ $item['title'] }}
                                @if ($item['unread'])<span class="sr-only">(belum dibaca)</span>@endif
                            </p>
                            @if ($item['body'])
                                <p class="m-0 text-sm text-ink-2">{{ $item['body'] }}</p>
                            @endif
                            <p class="m-0 text-xs text-muted">{{ $item['at']?->diffForHumans() }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                            @if ($item['hasTarget'])
                                <x-sth.button variant="soft" size="sm" icon-right="chevron-right" wire:click="open('{{ $item['id'] }}')">Buka</x-sth.button>
                            @endif
                            @if ($item['unread'])
                                <x-sth.icon-button icon="check" size="sm" :label="'Tandai dibaca: '.$item['title']" wire:click="markRead('{{ $item['id'] }}')" />
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-sth.card>

        @if ($notifications->hasPages())
            <div>{{ $notifications->links() }}</div>
        @endif
    @endif
</div>
