{{-- Profil talenta (UI-MAP #15, board Profil-Talenta). Data from App\Livewire\Siswa\ProfilTalenta::render(). --}}
<div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
    <div class="flex min-w-0 flex-col gap-5">
        {{-- Talenta pilihanmu --}}
        <x-sth.card as="section" aria-labelledby="h-pilihan" class="flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-2">
                    <h2 id="h-pilihan" class="title-card">Talenta pilihanmu</h2>
                    <x-sth.chip class="num">{{ count($rows) }}</x-sth.chip>
                </div>
                <p class="muted m-0 text-[13px]"><span class="sr-only">Tahapan: </span>Terdaftar › Pemetaan › Seleksi/asesmen › Peserta pembinaan</p>
            </div>

            @if ($undo)
                <div role="status" class="flex flex-wrap items-center justify-between gap-2 rounded-tile bg-gray-bg px-3.5 py-2.5 text-sm">
                    <span>{{ $undo['title'] }} dibatalkan.</span>
                    <x-sth.button variant="quiet" size="sm" wire:click="urungkan" wire:loading.attr="disabled">Urungkan</x-sth.button>
                </div>
            @endif

            @if ($rows === [])
                <x-sth.empty-state icon="user" title="Belum ada talenta" :card="false">
                    Pilih jenis talenta di bawah untuk ikut penjaringan.
                </x-sth.empty-state>
            @else
                <ul class="m-0 flex list-none flex-col gap-3 p-0">
                    @foreach ($rows as $row)
                        <li wire:key="tl-{{ $row['id'] }}" class="flex flex-col gap-3 rounded-tile border border-line p-3.5 sm:flex-row sm:items-start">
                            @if ($row['bidang'])
                                <x-sth.bidang-tile :bidang="$row['bidang']" size="sm" />
                            @endif
                            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="display m-0 text-[16px] font-bold">{{ $row['title'] }}</h3>
                                    @if ($row['bidang'])
                                        <x-sth.chip :bidang="$row['bidang']" size="sm" />
                                    @endif
                                    <x-sth.chip :tone="$row['status']->tone()" :icon="$row['status'] === \App\Enums\TalentStatus::Pembinaan ? 'check' : 'clock'" size="sm">{{ $row['status']->label() }}</x-sth.chip>
                                </div>
                                <span class="text-sm text-ink-2">{{ $row['info'] }}</span>
                                @if ($row['note'])
                                    <p class="m-0 rounded-control bg-subtle px-3 py-2 text-[13.5px] whitespace-pre-line break-words"><span class="font-bold">Catatan dari Kesiswaan:</span> {{ $row['note'] }}</p>
                                @endif
                                <div class="muted flex flex-wrap items-center gap-2 text-[13px]">
                                    @if ($row['step'] !== null)
                                        <span class="sg-row w-24" aria-hidden="true">
                                            @for ($i = 1; $i <= 4; $i++)
                                                <span @class(['sg', 'sg-done' => $i < $row['step'], 'sg-cur' => $i === $row['step']])></span>
                                            @endfor
                                        </span>
                                        <span class="num">Tahap {{ $row['step'] }} dari 4</span>
                                        <span aria-hidden="true">·</span>
                                    @endif
                                    @if ($row['evidence'])
                                        @if ($row['evidenceUrl'])
                                            <a href="{{ $row['evidenceUrl'] }}" class="lnk ellip min-h-0 max-w-56">{{ $row['evidence'] }}</a>
                                        @else
                                            <span class="ellip max-w-56">{{ $row['evidence'] }}</span>
                                        @endif
                                    @else
                                        <span>Tanpa bukti</span>
                                    @endif
                                </div>
                            </div>
                            @if ($row['canCancel'])
                                <x-sth.button variant="quiet" size="sm" wire:click="batalkan('{{ $row['id'] }}')" wire:loading.attr="disabled"
                                    aria-label="Batalkan {{ $row['title'] }}">Batalkan</x-sth.button>
                            @endif
                            @if ($row['canRequestReview'])
                                <x-sth.button variant="quiet" size="sm" wire:click="mintaTinjau('{{ $row['id'] }}')" wire:loading.attr="disabled"
                                    aria-haspopup="dialog" aria-label="Minta ditinjau ulang {{ $row['title'] }}">Minta ditinjau ulang</x-sth.button>
                            @elseif ($row['reviewRequested'])
                                <x-sth.chip tone="orange" icon="clock" size="sm" class="self-start">Menunggu tinjauan ulang</x-sth.chip>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @error('pilihan')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
        </x-sth.card>

        {{-- Tambah talenta --}}
        <x-sth.card as="section" aria-labelledby="h-tambah" class="flex flex-col gap-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="h-tambah" class="title-card">Tambah talenta</h2>
                <span class="muted text-[13px]">Penjaringan pembinaan talenta {{ $year }}</span>
            </div>

            <div role="group" aria-labelledby="lbl-jenis" class="flex flex-col gap-2.5">
                <span id="lbl-jenis" class="label inline-flex items-center gap-2">
                    <span @class(['inline-flex size-6 items-center justify-center rounded-full text-xs font-bold', 'bg-primary text-white' => $selectedType === null, 'bg-green-bg text-green' => $selectedType !== null]) aria-hidden="true">1</span>
                    Pilih jenis talenta
                </span>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($types as $type)
                        <button type="button" wire:key="jenis-{{ $type->id }}" wire:click="pilihJenis('{{ $type->id }}')"
                            aria-pressed="{{ $jenis === $type->id ? 'true' : 'false' }}"
                            @class(['pick flex min-h-14 items-center justify-start gap-3 px-3 text-left', 'pick-on' => $jenis === $type->id])>
                            <x-sth.bidang-tile :bidang="$type->bidang" size="sm" />
                            <span class="flex flex-col items-start gap-1">
                                <span class="font-bold">{{ $type->code->label() }}</span>
                                <x-sth.chip :bidang="$type->bidang" size="sm" />
                            </span>
                        </button>
                    @endforeach
                </div>
                @error('jenis')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
            </div>

            <div role="group" aria-labelledby="lbl-cabang" class="flex flex-col gap-2.5">
                <div class="flex items-center justify-between gap-2">
                    <span id="lbl-cabang" class="label inline-flex items-center gap-2">
                        <span @class(['inline-flex size-6 items-center justify-center rounded-full text-xs font-bold', 'bg-primary text-white' => $selectedType !== null && $cabang === '' && $selectedType->requiresBranch(), 'bg-green-bg text-green' => $cabang !== '' || ($selectedType !== null && ! $selectedType->requiresBranch()), 'bg-gray-bg text-gray' => $selectedType === null]) aria-hidden="true">2</span>
                        {{ $selectedType === null ? 'Pilih cabang' : ($selectedType->requiresBranch() ? 'Pilih cabang '.$selectedType->code->label() : 'Cabang') }}
                    </span>
                    @if ($branches !== [])
                        <span class="muted num text-[13px]">{{ count($branches) }} cabang</span>
                    @endif
                </div>
                @if ($selectedType === null)
                    <p class="note m-0 text-sm text-muted">Cabang muncul setelah jenis talenta dipilih.</p>
                @elseif (! $selectedType->requiresBranch())
                    <p class="note m-0 text-sm text-muted">Tidak perlu memilih cabang.</p>
                @else
                    <div class="flex flex-wrap gap-2">
                        @foreach ($branches as $branch)
                            <button type="button" wire:key="cabang-{{ $branch['id'] }}" wire:click="pilihCabang('{{ $branch['id'] }}')"
                                aria-pressed="{{ $cabang === $branch['id'] ? 'true' : 'false' }}"
                                @class(['fchip gap-2', 'fchip-on' => $cabang === $branch['id']])>
                                {{ $branch['name'] }}
                                @if ($branch['team'])<span class="chip chip-sm c-gray">{{ $branch['team'] }}</span>@endif
                                @if ($branch['rejected'])<span class="chip chip-sm c-red">Tidak lolos</span>@elseif ($branch['taken'])<span class="chip chip-sm c-green">Sudah dipilih</span>@endif
                            </button>
                        @endforeach
                    </div>
                @endif
                @error('cabang')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
            </div>

            <div class="flex flex-col gap-2.5">
                <span class="label inline-flex items-center gap-2">
                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-gray-bg text-xs font-bold text-gray" aria-hidden="true">3</span>
                    Bukti dukung (sertifikat) <span class="muted font-medium">opsional</span>
                </span>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div class="min-w-0 flex-1">
                        @if ($bukti)
                            @include('livewire.siswa.lomba-saya.partials.upload-row', ['name' => $bukti->getClientOriginalName(), 'size' => $bukti->getSize(), 'remove' => 'hapusBukti', 'removeLabel' => 'Hapus bukti dukung'])
                            @error('bukti')<p class="field-error m-0 mt-1"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                        @else
                            <x-sth.file-drop wire:model="bukti" :file="$bukti" id="talenta-bukti" title="Upload sertifikat"
                                hint="PDF, JPG, atau PNG · maks. 5 MB" accept=".pdf,.jpg,.jpeg,.png,.webp" />
                        @endif
                    </div>
                    <div class="flex flex-col gap-1.5 sm:items-end">
                        <x-sth.button icon="plus" wire:click="daftar" wire:loading.attr="disabled" wire:target="daftar,bukti"
                            :disabled="$helper !== null" :aria-describedby="$helper ? 'daftar-help' : null">Daftar penjaringan</x-sth.button>
                        @if ($helper)
                            <span id="daftar-help" class="muted text-[13px]">{{ $helper }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </x-sth.card>
    </div>

    <div class="flex min-w-0 flex-col gap-5">
        <x-sth.card as="section" aria-label="Data akun" class="flex items-center gap-3.5">
            <x-sth.avatar :name="$identity['name']" size="lg" />
            <div class="flex min-w-0 flex-col">
                <span class="display text-[16px] font-bold">{{ $identity['name'] }}</span>
                <span class="num text-sm">{{ $identity['meta'] }}</span>
                <span class="muted text-[12.5px]">Data diambil dari akunmu.</span>
            </div>
        </x-sth.card>

        <x-sth.card as="section" aria-labelledby="h-riwayat" class="flex flex-col gap-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="h-riwayat" class="title-card">Riwayat talenta</h2>
                <span class="muted text-[13px]">Dipakai saat pemetaan</span>
            </div>

            <form wire:submit="simpan" class="flex flex-col gap-4" novalidate>
                <div class="flex flex-col gap-2">
                    <label for="minat-baru" class="label">Minat <span class="muted font-medium">maks. {{ \App\Models\TalentProfile::MAX_INTERESTS }}</span></label>
                    <div class="flex flex-wrap items-center gap-2 rounded-control border border-line-input p-2">
                        @foreach ($minat as $i => $item)
                            <button type="button" wire:key="minat-{{ $i }}" wire:click="hapusMinat({{ $i }})" class="chip c-blue gap-1.5" aria-label="Hapus minat {{ $item }}">
                                {{ $item }}<x-sth.icon name="x" :size="14" />
                            </button>
                        @endforeach
                        @if (count($minat) < \App\Models\TalentProfile::MAX_INTERESTS)
                            <input id="minat-baru" type="text" wire:model="minatBaru" wire:keydown.enter.prevent="tambahMinat" maxlength="40"
                                class="min-h-9 min-w-32 flex-1 border-0 bg-transparent px-1 text-[15px] outline-none" placeholder="Tambah minat" @error('minat') aria-invalid="true" aria-describedby="minat-error" @enderror>
                            <x-sth.icon-button icon="plus" label="Tambah minat" size="sm" wire:click="tambahMinat" />
                        @endif
                    </div>
                    @error('minat')<p id="minat-error" class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                </div>

                <x-sth.input wire:model="dikuasai" id="dikuasai" label="Bidang yang dikuasai" maxlength="300" :error="$errors->first('dikuasai')" />
                <x-sth.textarea wire:model="organisasi" id="organisasi" label="Pengalaman organisasi/ekstrakurikuler" rows="2" :error="$errors->first('organisasi')" />

                <div class="flex flex-col gap-2">
                    <span id="lbl-lomba" class="label">Pengalaman lomba</span>
                    <div role="group" aria-labelledby="lbl-lomba" class="flex items-center justify-between gap-2 rounded-control bg-subtle px-3.5 py-2">
                        <span class="text-sm"><span class="num font-bold">{{ $lombaCount }}</span> lomba tercatat di STH</span>
                        <x-sth.link :href="$portfolioUrl">Lihat portofolio</x-sth.link>
                    </div>
                </div>

                <x-sth.textarea wire:model="smp" id="smp" label="Prestasi SMP" rows="2" :error="$errors->first('smp')" />

                <div class="flex flex-col gap-2">
                    <span id="lbl-sert" class="label">Sertifikat pendukung <span class="muted font-medium">maks. {{ \App\Models\TalentProfile::MAX_CERTIFICATES }}</span></span>
                    @foreach ($certificates as $file)
                        <div wire:key="sert-{{ $file['id'] }}" class="flex items-center gap-3 rounded-tile border border-line p-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-control bg-primary-tint text-primary" aria-hidden="true"><x-sth.icon name="file" /></span>
                            <span class="flex min-w-0 flex-1 flex-col">
                                @if ($file['url'])
                                    <a href="{{ $file['url'] }}" class="ellip text-sm font-bold text-ink">{{ $file['name'] }}</a>
                                @else
                                    <span class="ellip text-sm font-bold">{{ $file['name'] }}</span>
                                @endif
                                <span class="muted text-[12.5px]">Tersimpan</span>
                            </span>
                            <x-sth.icon-button icon="trash" label="Hapus {{ $file['name'] }}" size="sm" wire:click="hapusSertifikat('{{ $file['id'] }}')" />
                        </div>
                    @endforeach
                    @foreach ($sertifikatBaru as $i => $upload)
                        <div wire:key="sert-baru-{{ $i }}">
                            @include('livewire.siswa.lomba-saya.partials.upload-row', ['name' => $upload->getClientOriginalName(), 'size' => $upload->getSize(), 'remove' => "hapusSertifikatBaru({$i})", 'removeLabel' => 'Hapus '.$upload->getClientOriginalName()])
                        </div>
                    @endforeach
                    @if ($slotsLeft > 0)
                        <x-sth.file-drop wire:model="sertifikatPilih" :file="$sertifikatPilih" id="sertifikat-pilih" title="Upload sertifikat" hint="PDF, JPG, atau PNG · maks. 5 MB"
                            accept=".pdf,.jpg,.jpeg,.png,.webp" />
                    @endif
                    @error('sertifikat')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                </div>

                <div class="flex items-center justify-end gap-3">
                    @if ($tersimpan)
                        <span role="status" class="inline-flex items-center gap-1.5 text-sm font-semibold text-green"><x-sth.icon name="check" :size="16" />Tersimpan</span>
                    @endif
                    <x-sth.button type="submit" variant="quiet" size="sm" wire:loading.attr="disabled" wire:target="simpan">Simpan</x-sth.button>
                </div>
            </form>
        </x-sth.card>
    </div>

    {{-- "Minta ditinjau ulang": a rejected choice, one open request per rejection. Kesiswaan decides. --}}
    @if ($hasRejected)
        <x-sth.modal :id="\App\Livewire\Siswa\ProfilTalenta::REVIEW_DIALOG" title="Minta ditinjau ulang?" description="Kesiswaan akan membaca permintaanmu. Statusmu baru berubah kalau Kesiswaan membuka kembali pilihan ini." size="sm">
            <form id="tinjau-ulang-form" wire:submit="kirimTinjau" class="flex flex-col gap-4" novalidate>
                @if ($reviewing)
                    <p class="m-0 text-sm font-bold">{{ $reviewing }}</p>
                @endif
                <x-sth.textarea wire:model="pesanTinjau" label="Pesan untuk Kesiswaan" optional rows="3" maxlength="{{ $reviewMax }}"
                    hint="Maks. {{ $reviewMax }} karakter. Contoh: alasan atau bukti baru yang kamu punya." />
            </form>
            <x-slot:footer>
                <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
                <x-sth.button type="submit" form="tinjau-ulang-form" icon="send" wire:loading.attr="disabled" wire:target="kirimTinjau">Kirim permintaan</x-sth.button>
            </x-slot:footer>
        </x-sth.modal>
    @endif
</div>
