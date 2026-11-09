{{-- SV8, D-199: "Ubah pendaftaran" / "Ajukan revisi" of the ketua on Lomba saya. Rules and texts:
     App\Livewire\Siswa\LombaSaya\UbahPendaftaran (the Actions decide). The root element comes first (Livewire puts
     wire:id on it). --}}
<div>
    @if ($pending || $canOpen || $last)
        <x-sth.card as="section" aria-labelledby="ubah-judul" class="flex flex-col gap-3 [overflow-wrap:anywhere]">
            <h3 id="ubah-judul" class="title-card m-0">{{ $before ? 'Ubah pendaftaran' : 'Revisi pendaftaran' }}</h3>

            @if ($pending)
                <x-sth.alert tone="info" title="Revisi menunggu keputusan">
                    {{ $pending['ready'] ? 'Tata Usaha atau Kesiswaan sedang memutuskan revisi ini.' : 'Siswa yang ditambah perlu menerima undangan dulu, lalu Tata Usaha atau Kesiswaan memutuskan revisi ini.' }}
                    Tim yang sudah disetujui tetap berlaku.
                </x-sth.alert>
                <dl class="m-0 grid grid-cols-[112px_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                    @if ($pending['added'] !== [])
                        <dt class="text-muted">Ditambah</dt>
                        <dd class="m-0 flex flex-col gap-1.5">
                            @foreach ($pending['added'] as $row)
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{ $row['name'] }}</span>
                                    <x-sth.chip :tone="$row['state'][1]" size="sm">{{ $row['state'][0] }}</x-sth.chip>
                                </span>
                            @endforeach
                        </dd>
                    @endif
                    @if ($pending['removed'] !== [])
                        <dt class="text-muted">Dikeluarkan</dt>
                        <dd class="m-0 font-semibold">{{ implode(', ', $pending['removed']) }}</dd>
                    @endif
                    @if ($pending['mentor'])
                        <dt class="text-muted">Guru pembimbing</dt>
                        <dd class="m-0 font-semibold">{{ $pending['mentor'] }}</dd>
                    @endif
                    <dt class="text-muted">Alasan</dt>
                    <dd class="m-0">{{ $pending['reason'] }}</dd>
                    @if ($pending['sentOn'])
                        <dt class="text-muted">Dikirim</dt>
                        <dd class="m-0">{{ $pending['sentOn'] }}</dd>
                    @endif
                </dl>
                <x-sth.button variant="quiet" size="sm" icon="x" class="self-start" wire:click="askCancelRevision" aria-haspopup="dialog">Batalkan revisi</x-sth.button>
            @else
                @if ($last)
                    <x-sth.alert :tone="$last['tone']">{{ $last['text'] }}</x-sth.alert>
                @endif
                @if ($canOpen)
                    <p class="m-0 text-sm text-ink-2">
                        @if ($before)
                            {{ $isTeam ? 'Tambah atau keluarkan anggota, atau ganti guru pembimbing. Perubahan langsung disimpan.' : 'Ganti guru pembimbing. Perubahan langsung disimpan.' }}
                        @else
                            Pendaftaran sudah disetujui. Perubahan {{ $isTeam ? 'tim atau ' : '' }}guru pembimbing diajukan sebagai revisi dan diputuskan Tata Usaha atau Kesiswaan.
                        @endif
                    </p>
                    <x-sth.button variant="soft" size="sm" icon="edit" class="self-start" wire:click="open" aria-haspopup="dialog">
                        {{ $before ? 'Ubah pendaftaran' : 'Ajukan revisi' }}
                    </x-sth.button>
                @endif
            @endif
        </x-sth.card>
    @endif

    <x-sth.modal :id="\App\Livewire\Siswa\LombaSaya\UbahPendaftaran::DIALOG" size="lg" :title="$before ? 'Ubah pendaftaran' : 'Ajukan revisi'"
        :description="$before ? 'Perubahan langsung disimpan.' : 'Tata Usaha atau Kesiswaan memutuskan revisi ini.'">
        <form id="ubah-pendaftaran-form" wire:submit="simpan" class="flex flex-col gap-4" novalidate>
            @if ($form)
                @error('changes')
                    <x-sth.alert tone="error">{{ $message }}</x-sth.alert>
                @enderror

                @if ($isTeam)
                    <section aria-labelledby="ubah-tim" class="flex flex-col gap-2">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h4 id="ubah-tim" class="title-card m-0">Tim</h4>
                            <span class="text-xs text-muted"><span class="num">{{ $form['people'] }}</span> orang{{ $form['max'] ? ', maks '.$form['max'] : '' }}</span>
                        </div>
                        <ul class="m-0 flex list-none flex-col gap-2 p-0">
                            @foreach ($form['members'] as $member)
                                <li wire:key="ubah-m-{{ $member['studentId'] }}" class="flex flex-wrap items-center justify-between gap-2 rounded-tile border border-line px-3 py-2">
                                    <span class="flex min-w-0 flex-col">
                                        <span class="text-sm font-semibold">{{ $member['name'] }}</span>
                                        <span class="text-xs text-muted">{{ $member['kelas'] }} · {{ $member['leader'] ? 'Ketua' : $member['state'] }}</span>
                                    </span>
                                    @if ($member['removable'])
                                        <label class="inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold">
                                            <input type="checkbox" class="cek" value="{{ $member['studentId'] }}" wire:model.live="keluarkan">
                                            Keluarkan
                                        </label>
                                    @endif
                                </li>
                            @endforeach
                            @foreach ($form['added'] as $row)
                                <li wire:key="ubah-a-{{ $row['id'] }}" class="flex flex-wrap items-center justify-between gap-2 rounded-tile border border-primary-soft bg-primary-tint/40 px-3 py-2">
                                    <span class="flex min-w-0 flex-col">
                                        <span class="text-sm font-semibold">{{ $row['name'] }}</span>
                                        <span class="text-xs text-muted">{{ $row['kelas'] }} · Ditambah</span>
                                    </span>
                                    <x-sth.button variant="quiet" size="sm" icon="x" wire:click="hapusTambah('{{ $row['id'] }}')">Batal tambah</x-sth.button>
                                </li>
                            @endforeach
                        </ul>

                        @if ($form['windowClosed'])
                            <p class="m-0 text-xs text-muted">{{ \App\Actions\Registrations\RevisionRules::MSG_WINDOW }}</p>
                        @elseif ($form['full'])
                            <p class="m-0 text-xs text-muted">Tim sudah penuh. Keluarkan anggota untuk mengganti.</p>
                        @else
                            <x-sth.input wire:model.live.debounce.400ms="q" id="ubah-cari" label="Tambah anggota" placeholder="Nama atau NISN" autocomplete="off" />
                            @if ($searchNote)
                                <p class="m-0 text-xs text-muted">{{ $searchNote }}</p>
                            @endif
                            @if ($hasil !== [])
                                <ul class="m-0 flex list-none flex-col gap-1.5 p-0" aria-label="Hasil pencarian">
                                    @foreach ($hasil as $row)
                                        <li wire:key="ubah-h-{{ $row['id'] }}" class="flex flex-wrap items-center justify-between gap-2 rounded-tile border border-line px-3 py-2">
                                            <span class="flex min-w-0 flex-col">
                                                <span class="text-sm font-semibold">{{ $row['name'] }}</span>
                                                <span class="text-xs text-muted">{{ $row['kelas'] }}@if ($row['pending']) · belum aktivasi akun @endif</span>
                                            </span>
                                            @if ($row['inTeam'])
                                                <span class="text-xs text-muted">Sudah di tim</span>
                                            @elseif ($row['declined'])
                                                <span class="text-xs text-muted">Sudah menolak tim ini</span>
                                            @elseif ($row['busy'])
                                                <span class="text-xs text-muted">Sudah terdaftar di lomba ini</span>
                                            @elseif (in_array($row['id'], $tambah, true))
                                                <span class="text-xs text-muted">Ditambah</span>
                                            @else
                                                <x-sth.button variant="soft" size="sm" icon="plus" wire:click="tambahAnggota('{{ $row['id'] }}')">Tambah</x-sth.button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                        @error('tambah')
                            <p class="m-0 text-sm text-red" role="alert">{{ $message }}</p>
                        @enderror
                    </section>
                @endif

                <section aria-labelledby="ubah-guru" class="flex flex-col gap-2">
                    <h4 id="ubah-guru" class="title-card m-0">Guru pembimbing</h4>
                    <p class="m-0 text-sm text-ink-2">Sekarang: <span class="font-semibold">{{ $form['currentMentor'] ?? 'belum ada' }}</span></p>
                    <x-sth.select wire:model.live="guru" id="ubah-guru-pilih" label="Guru pembimbing" hide-label placeholder="Pilih guru" :options="$form['mentors']" />
                </section>

                @unless ($before)
                    <x-sth.textarea wire:model="alasan" id="ubah-alasan" label="Alasan revisi" rows="3" hint="10–500 karakter. Dibaca Tata Usaha dan Kesiswaan." />
                @endunless

                <ul class="m-0 flex list-disc flex-col gap-1 pl-5 text-sm text-ink-2">
                    @foreach ($form['notices'] as $notice)
                        <li>{{ $notice }}</li>
                    @endforeach
                </ul>
            @else
                <p class="m-0 text-md text-muted">Tutup lalu buka lagi untuk mengubah pendaftaran.</p>
            @endif
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($form)
                <x-sth.button type="submit" form="ubah-pendaftaran-form" icon="check" wire:loading.attr="disabled" wire:target="simpan">
                    {{ $before ? 'Simpan perubahan' : 'Kirim revisi' }}
                </x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>

    {{-- D-128: cancelling asks first and says who is told. --}}
    <x-sth.modal :id="\App\Livewire\Siswa\LombaSaya\UbahPendaftaran::CANCEL_DIALOG" size="sm" title="Batalkan revisi?"
        description="Siswa yang diundang lewat revisi ini diberi tahu bahwa undangannya tidak berlaku lagi.">
        <p class="m-0 text-sm">Revisi ini tidak jadi diputuskan. Tim yang sudah disetujui tetap berlaku.</p>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Kembali</x-sth.button>
            <x-sth.button variant="danger" wire:click="cancelRevision" wire:loading.attr="disabled">Batalkan revisi</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>
</div>
