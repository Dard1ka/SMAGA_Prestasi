{{-- Perubahan peran (backlog #13, B5): embedded in Admin › Pengguna & peran. Since 21 Okt 2026 role changes apply at once,
     so this lists only requests made before that day (pending, or finished in the last 30 days). Renders nothing visible when empty. --}}
<div>
    @if ($pending->isNotEmpty() || $recent->isNotEmpty())
        <x-sth.card as="section" aria-labelledby="h-peran-menunggu" class="flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <h3 id="h-peran-menunggu" class="title-card">Perubahan peran</h3>
                    @if ($pending->isNotEmpty())
                        <span class="cnt">{{ $pending->count() }}</span>
                    @endif
                </div>
                <p class="text-sm muted">Peran yang diubah sekarang langsung berlaku. Daftar ini hanya memuat permintaan lama yang belum selesai dan yang selesai 30 hari terakhir.</p>
            </div>

            @if ($pending->isNotEmpty())
                <ul class="m-0 flex list-none flex-col gap-3 p-0" aria-label="Menunggu">
                    @foreach ($pending as $row)
                        @php $r = $row['request']; @endphp
                        <li wire:key="peran-{{ $r->id }}" class="flex flex-wrap items-center gap-3 rounded-tile border border-line p-4">
                            <x-sth.avatar :name="$r->user?->name ?? '-'" size="sm" />
                            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="font-semibold">{{ $r->user?->name ?? 'Akun dihapus' }} → {{ $r->to_role->label() }}</span>
                                <span class="text-sm muted">
                                    Diminta {{ $r->requester?->name ?? '-' }}, {{ $r->created_at?->translatedFormat('j M Y H:i') }} · Alasan: {{ $r->reason }}
                                </span>
                            </div>
                            @if ($r->usesDelay())
                                <x-sth.chip tone="orange" icon="clock" size="sm">Berlaku {{ $r->effective_at->translatedFormat('j M H:i') }}</x-sth.chip>
                            @else
                                <x-sth.chip tone="blue" size="sm">Belum diputuskan</x-sth.chip>
                            @endif
                            <span class="flex w-full flex-wrap justify-end gap-2 sm:w-auto">
                                @if ($row['canApprove'])
                                    <x-sth.button variant="soft" size="sm" icon="check" wire:click="approve('{{ $r->id }}')" wire:loading.attr="disabled" wire:target="approve">
                                        Setujui<span class="sr-only"> perubahan peran {{ $r->user?->name }}</span>
                                    </x-sth.button>
                                @endif
                                @if ($row['canCancel'])
                                    <x-sth.button variant="quiet" size="sm" wire:click="openCancel('{{ $r->id }}')">
                                        Batalkan<span class="sr-only"> perubahan peran {{ $r->user?->name }}</span>
                                    </x-sth.button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="m-0 text-sm muted">Tidak ada yang menunggu.</p>
            @endif

            @if ($recent->isNotEmpty())
                <div class="flex flex-col gap-2">
                    <h4 class="m-0 text-md font-bold">Terakhir</h4>
                    <ul class="m-0 flex list-none flex-col gap-1.5 p-0 text-sm">
                        @foreach ($recent as $r)
                            <li wire:key="peran-selesai-{{ $r->id }}" class="flex flex-wrap items-center gap-2">
                                @if ($r->applied_at)
                                    <x-sth.chip tone="green" size="sm">Berlaku</x-sth.chip>
                                @else
                                    <x-sth.chip tone="gray" size="sm">Dibatalkan</x-sth.chip>
                                @endif
                                <span>{{ $r->user?->name ?? '-' }} → {{ $r->to_role->label() }}</span>
                                <span class="muted">
                                    · {{ ($r->applied_at ?? $r->cancelled_at)?->translatedFormat('j M Y H:i') }}
                                    @if ($r->applied_at && $r->approver) · disetujui {{ $r->approver->name }} @endif
                                    @if ($r->cancelled_at) · {{ $r->canceller?->name ?? 'otomatis' }}: {{ $r->cancel_reason }} @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </x-sth.card>
    @endif

    <x-sth.modal id="batal-peran" title="Batalkan perubahan peran?" description="Peran akun tetap seperti sekarang." size="sm">
        <form id="batal-peran-form" wire:submit="cancel" class="flex flex-col gap-4" novalidate>
            <x-sth.textarea wire:model="cancelReason" label="Alasan" rows="2" />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Tutup</x-sth.button>
            <x-sth.button type="submit" variant="danger" form="batal-peran-form" wire:loading.attr="disabled" wire:target="cancel">Batalkan perubahan</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>
</div>
