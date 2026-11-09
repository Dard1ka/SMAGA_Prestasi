{{-- SV8, D-199: Revisi pendaftaran (Tata Usaha and Kesiswaan). Data + rules: App\Livewire\Sekolah\RevisiPendaftaran. --}}
<div class="flex flex-col gap-4">
    <nav class="seg self-start" aria-label="Revisi pendaftaran">
        @foreach (['menunggu' => 'Menunggu', 'riwayat' => 'Riwayat'] as $key => $label)
            <a href="{{ route($routeName, $key === 'menunggu' ? [] : ['tab' => $key]) }}" wire:navigate
               @class(['seg-btn', 'seg-on' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>
                {{ $label }}<span class="cnt num">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    @if ($rows === [])
        <x-sth.empty-state icon="inbox" :title="$tab === 'riwayat' ? 'Belum ada revisi yang diputuskan' : 'Tidak ada revisi yang menunggu'">
            Revisi muncul di sini saat ketua tim mengubah pendaftaran yang sudah disetujui.
        </x-sth.empty-state>
    @else
        <ul class="m-0 flex list-none flex-col gap-3 p-0">
            @foreach ($rows as $row)
                <li wire:key="revisi-{{ $row['id'] }}">
                    <x-sth.card as="article" class="flex flex-col gap-3 [overflow-wrap:anywhere]" aria-label="Revisi {{ $row['lomba'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <h3 class="m-0 text-lg leading-snug font-bold">{{ $row['lomba'] }}</h3>
                                <p class="m-0 text-sm text-muted">Ketua: {{ $row['leader'] }} · dikirim {{ $row['sentOn'] }}</p>
                            </div>
                            <span class="flex flex-wrap gap-2">
                                <x-sth.chip :tone="$row['registrationStatus'][0]" size="sm">{{ $row['registrationStatus'][1] }}</x-sth.chip>
                                @if ($row['letters'])
                                    <x-sth.chip tone="blue" size="sm">Surat tugas sudah terbit</x-sth.chip>
                                @endif
                                @unless ($row['pending'])
                                    <x-sth.chip :tone="$row['status'][0]" size="sm">{{ $row['status'][1] }}</x-sth.chip>
                                @endunless
                            </span>
                        </div>

                        <dl class="m-0 grid grid-cols-1 gap-x-3 gap-y-2 text-sm sm:grid-cols-[150px_minmax(0,1fr)]">
                            @if ($row['added'] !== [])
                                <dt class="text-muted">Ditambah</dt>
                                <dd class="m-0 flex flex-col gap-1.5">
                                    @foreach ($row['added'] as $added)
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="font-semibold">{{ $added['name'] }}</span>
                                            <x-sth.chip :tone="$added['state'][1]" size="sm">{{ $added['state'][0] }}</x-sth.chip>
                                        </span>
                                    @endforeach
                                </dd>
                            @endif
                            @if ($row['removed'] !== [])
                                <dt class="text-muted">Dikeluarkan</dt>
                                <dd class="m-0 font-semibold">{{ implode(', ', $row['removed']) }}</dd>
                            @endif
                            @if ($row['mentorTo'])
                                <dt class="text-muted">Guru pembimbing</dt>
                                <dd class="m-0"><span class="font-semibold">{{ $row['mentorTo'] }}</span>, menggantikan {{ $row['mentorFrom'] }}</dd>
                            @endif
                            <dt class="text-muted">Alasan ketua</dt>
                            <dd class="m-0">{{ $row['reason'] }}</dd>
                            @if ($row['decided'])
                                <dt class="text-muted">Diputuskan</dt>
                                <dd class="m-0">{{ $row['decided'] }}</dd>
                            @endif
                            @if ($row['note'])
                                <dt class="text-muted">Catatan</dt>
                                <dd class="m-0">{{ $row['note'] }}</dd>
                            @endif
                        </dl>

                        @if ($row['pending'])
                            @if ($row['problem'])
                                <x-sth.alert tone="warning">{{ $row['problem'] }} Revisi ini hanya bisa ditolak.</x-sth.alert>
                            @elseif (! $row['ready'])
                                <p class="m-0 text-sm text-muted">Menunggu siswa yang ditambah menerima undangan.</p>
                            @endif
                            <div class="flex flex-wrap gap-2">
                                @if ($row['ready'] && ! $row['problem'])
                                    <x-sth.button icon="check" size="sm" wire:click="askApprove('{{ $row['id'] }}')" aria-haspopup="dialog">Setujui</x-sth.button>
                                @endif
                                <x-sth.button variant="quiet" size="sm" icon="x" wire:click="askReject('{{ $row['id'] }}')" aria-haspopup="dialog">Tolak</x-sth.button>
                            </div>
                        @endif
                    </x-sth.card>
                </li>
            @endforeach
        </ul>
        @if ($more)
            <div class="flex justify-center">
                <x-sth.button variant="quiet" size="sm" wire:click="showMore">Tampilkan lebih banyak</x-sth.button>
            </div>
        @endif
    @endif

    {{-- D-128: both decisions ask first and say who is told. --}}
    <x-sth.modal :id="\App\Livewire\Sekolah\RevisiPendaftaran::APPROVE_DIALOG" size="sm" title="Setujui revisi?" description="Keputusan ini tidak bisa dibatalkan dari layar ini.">
        <form id="revisi-setujui-form" wire:submit="approve" class="flex flex-col gap-3" novalidate>
            @if ($dialog)
                <p class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $dialog['row']['lomba'] }} · {{ $dialog['row']['leader'] }}</p>
                <ul class="m-0 flex list-disc flex-col gap-1 pl-5 text-sm text-ink-2">
                    @foreach ($dialog['notices'] as $notice)
                        <li>{{ $notice }}</li>
                    @endforeach
                </ul>
                <x-sth.textarea wire:model="note" id="revisi-catatan-setujui" label="Catatan" rows="2" optional hint="Maks. 500 karakter." />
            @else
                <p class="m-0 text-md text-muted">Tidak ada revisi yang dipilih. Tutup lalu coba lagi.</p>
            @endif
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($dialog)
                <x-sth.button type="submit" form="revisi-setujui-form" icon="check" wire:loading.attr="disabled" wire:target="approve">Setujui revisi</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>

    <x-sth.modal :id="\App\Livewire\Sekolah\RevisiPendaftaran::REJECT_DIALOG" size="sm" title="Tolak revisi?" description="Keputusan ini tidak bisa dibatalkan dari layar ini.">
        <form id="revisi-tolak-form" wire:submit="reject" class="flex flex-col gap-3" novalidate>
            @if ($dialog)
                <p class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $dialog['row']['lomba'] }} · {{ $dialog['row']['leader'] }}</p>
                <ul class="m-0 flex list-disc flex-col gap-1 pl-5 text-sm text-ink-2">
                    @foreach ($dialog['notices'] as $notice)
                        <li>{{ $notice }}</li>
                    @endforeach
                </ul>
                <x-sth.textarea wire:model="note" id="revisi-catatan-tolak" label="Alasan penolakan" rows="3" hint="5–500 karakter. Dibaca ketua tim." />
            @else
                <p class="m-0 text-md text-muted">Tidak ada revisi yang dipilih. Tutup lalu coba lagi.</p>
            @endif
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($dialog)
                <x-sth.button type="submit" form="revisi-tolak-form" variant="danger" wire:loading.attr="disabled" wire:target="reject">Tolak revisi</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
