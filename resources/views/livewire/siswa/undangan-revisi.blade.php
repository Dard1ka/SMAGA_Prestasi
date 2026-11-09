{{-- SV8, D-199: Siswa · Undangan revisi. Data + rules: App\Livewire\Siswa\UndanganRevisi. Root element first (Livewire). --}}
<div class="mx-auto flex w-full max-w-[760px] flex-col gap-[18px] [overflow-wrap:anywhere]">
    <x-sth.card as="section" aria-labelledby="h-undangan-revisi" class="flex flex-col gap-2">
        <span class="text-sm font-semibold text-muted">Undangan tim</span>
        <h2 id="h-undangan-revisi" class="title-page m-0 text-xl">{{ $competition?->name }}</h2>
        <p class="m-0 text-sm text-ink-2">{{ $leader }} mengajak kamu masuk tim ini. Pendaftarannya sudah disetujui sekolah, jadi perubahan tim diputuskan Tata Usaha atau Kesiswaan setelah kamu menerima undangan.</p>
    </x-sth.card>

    <x-sth.card as="section" aria-labelledby="h-tim-revisi" class="flex flex-col gap-3">
        <h2 id="h-tim-revisi" class="title-section m-0">Tim sekarang</h2>
        <ul class="m-0 flex list-none flex-col gap-2 p-0">
            @foreach ($members as $member)
                <li wire:key="rm-{{ $member['id'] }}" class="flex items-center gap-3 rounded-tile border border-line p-2.5">
                    <x-sth.avatar :name="$member['name']" size="sm" />
                    <span class="flex min-w-0 flex-col">
                        <span class="font-semibold">{{ $member['name'] }}</span>
                        <span class="text-xs text-muted">{{ $member['role'] }} · {{ $member['kelas'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
        <p class="m-0 text-sm text-muted">Pembimbing: <span class="font-semibold text-ink">{{ $mentor }}</span></p>
        @if ($removed !== [] || $newMentor)
            <div class="flex flex-col gap-1 rounded-tile bg-subtle px-3 py-2.5 text-sm">
                <span class="font-semibold">Perubahan lain dalam revisi ini</span>
                @if ($removed !== [])
                    <span>Keluar dari tim: {{ implode(', ', $removed) }}</span>
                @endif
                @if ($newMentor)
                    <span>Guru pembimbing baru: {{ $newMentor }}</span>
                @endif
            </div>
        @endif
        <p class="m-0 text-sm"><span class="text-muted">Alasan ketua:</span> {{ $reason }}</p>
    </x-sth.card>

    @error('revision')
        <x-sth.alert tone="error">{{ $message }}</x-sth.alert>
    @enderror

    @if ($canRespond)
        <x-sth.card as="section" tone="warn" aria-labelledby="h-jawab-revisi" class="flex flex-col gap-3">
            <h2 id="h-jawab-revisi" class="title-card m-0">Masuk tim ini?</h2>
            <section aria-labelledby="h-peserta-revisi" class="flex flex-col gap-3 rounded-tile border border-line bg-surface p-4">
                <h3 id="h-peserta-revisi" class="title-card m-0">Data peserta</h3>
                <dl class="m-0 grid grid-cols-[104px_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm">
                    @foreach ($peserta as $row)
                        <dt class="text-muted">{{ $row['label'] }}</dt>
                        <dd class="m-0 font-semibold">{{ $row['value'] }}</dd>
                    @endforeach
                </dl>
                <p class="m-0 text-xs text-muted">Data salah? Hubungi Kesiswaan.</p>
                <x-sth.input wire:model="phone" id="no-hp-revisi" type="tel" inputmode="tel" autocomplete="tel" label="No HP" hint="Contoh 081234567890." />
            </section>
            <div class="flex flex-wrap gap-2">
                <x-sth.button icon="check" wire:click="terima" wire:loading.attr="disabled">Terima undangan</x-sth.button>
                <x-sth.button variant="quiet" data-sth-open="{{ \App\Livewire\Siswa\UndanganRevisi::DECLINE_DIALOG }}" aria-haspopup="dialog">Tolak</x-sth.button>
            </div>
        </x-sth.card>

        <x-sth.modal :id="\App\Livewire\Siswa\UndanganRevisi::DECLINE_DIALOG" title="Tolak undangan?" size="sm"
            description="Revisi tim ini batal dan ketua tim diberi tahu. Keputusan ini tidak bisa diubah.">
            <p class="m-0">Kamu tidak masuk tim {{ $competition?->name }}.</p>
            <x-slot:footer>
                <x-sth.button variant="quiet" data-sth-close>Kembali</x-sth.button>
                <x-sth.button variant="danger" wire:click="tolak" wire:loading.attr="disabled">Tolak undangan</x-sth.button>
            </x-slot:footer>
        </x-sth.modal>
    @else
        @switch($state)
            @case('waiting')
                <x-sth.alert tone="success">Kamu sudah menerima undangan ini. Tim berubah setelah Tata Usaha atau Kesiswaan menyetujui revisinya.</x-sth.alert>
                @break
            @case('joined')
                <x-sth.alert tone="success">Revisi disetujui. Kamu sudah masuk tim ini.
                    @if ($lombaSayaUrl)<x-sth.link :href="$lombaSayaUrl" icon-right="chevron-right">Pantau di Lomba saya</x-sth.link>@endif
                </x-sth.alert>
                @break
            @case('declined')
                <x-sth.alert>Kamu sudah menolak undangan ini.</x-sth.alert>
                @break
            @case('rejected')
                <x-sth.alert tone="warning">Revisi tim ini tidak disetujui sekolah, jadi kamu tidak masuk tim itu.</x-sth.alert>
                @break
            @case('cancelled')
                <x-sth.alert tone="warning">Revisi tim ini sudah dibatalkan. Kamu tidak perlu menjawab undangannya lagi.</x-sth.alert>
                @break
            @default
                <x-sth.alert tone="warning">{{ \App\Actions\Registrations\RespondToRevision::MSG_NOT_VALID }}</x-sth.alert>
        @endswitch
    @endif
</div>
