{{-- Tata Usaha · Surat tugas (mockup TU-Surat-Tugas.dc.html): queue → 1 nomor → 2 pratinjau → 3 PDF + kirim. --}}
<div class="flex flex-col gap-[22px]">
    @if ($queue->isNotEmpty() || $registration)
        <nav aria-label="Antrean surat" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($queue as $item)
                @php
                    $key = $item->competition?->bidang?->cssKey() ?? 'sains';
                    $count = $item->members->count();
                    $isOn = $registration && $registration->id === $item->id;
                    $hasPdf = $item->letters->isNotEmpty();
                @endphp
                <a href="{{ route('tu.surat.show', $item) }}" wire:key="q-{{ $item->id }}" @if ($isOn) aria-current="page" @endif
                   @class(['pick flex items-center gap-3 p-3 text-left no-underline', 'pick-on' => $isOn])>
                    <span class="b-{{ $key }} inline-flex size-11 shrink-0 items-center justify-center rounded-tile" aria-hidden="true"><x-sth.icon :name="$key" /></span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="ellip text-[15px] font-bold text-ink">{{ $item->competition?->name }}</span>
                        <span class="muted ellip text-[13px]">{{ $item->branch ? $item->branch.' · ' : '' }}{{ $item->isTeam() ? 'Tim '.$count.' siswa' : $count.' siswa' }}</span>
                        <span @class(['text-[12.5px] font-semibold', 'text-green' => ! $hasPdf, 'text-blue' => $hasPdf])>
                            {{ $hasPdf ? 'PDF siap · belum dikirim' : 'Disetujui Kepala Sekolah · '.$item->principal_decided_at?->translatedFormat('j M') }}
                        </span>
                    </span>
                </a>
            @endforeach
        </nav>
    @endif

    @if (! $registration)
        <x-sth.empty-state icon="mail" title="Belum ada surat untuk dibuat">
            Pendaftaran yang disetujui Kepala Sekolah muncul di sini.
            @if (Route::has('tu.surat.arsip'))
                <x-slot:actions><x-sth.button variant="quiet" size="sm" href="{{ route('tu.surat.arsip') }}">Buka arsip surat</x-sth.button></x-slot:actions>
            @endif
        </x-sth.empty-state>
    @else
        @php
            // B3 (#11): while correcting a letter the form opens again for that letter only.
            $locked = $issued && ! $revising;
            $n = $content->studentCount();
        @endphp
        <div class="grid items-start gap-[22px] xl:grid-cols-[minmax(0,1fr)_minmax(0,560px)]">
            <div class="flex min-w-0 flex-col gap-[22px]">
                <x-sth.card as="section" class="flex flex-col gap-5" aria-labelledby="langkah-1">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="langkah-1" class="title-card">{{ $revising ? '1. Koreksi surat' : '1. Isi nomor surat' }}</h2>
                        <span class="muted text-[13px]">{{ $revising ? 'Versi baru memakai nomor baru' : ($locked ? 'Nomor sudah terbit, tidak bisa diubah' : 'Wajib sebelum membuat PDF') }}</span>
                    </div>

                    @if ($revising)
                        <x-sth.alert tone="warning" title="Surat {{ $revising->full_number }} akan dicabut">
                            Nomor lama tetap tercatat dan tidak dipakai lagi. Periksa data di bawah, isi nomor baru, lalu tulis alasannya di langkah 3.
                        </x-sth.alert>
                    @endif

                    @if ($locked)
                        <dl class="m-0 grid gap-3 sm:grid-cols-[180px_1fr]">
                            <dt class="text-sm font-semibold text-muted">Nomor surat siswa</dt>
                            <dd class="num m-0 text-sm font-bold">{{ $siswaNumber }}</dd>
                            @if ($guruNumber)
                                <dt class="text-sm font-semibold text-muted">Nomor surat guru</dt>
                                <dd class="num m-0 text-sm font-bold">{{ $guruNumber }}</dd>
                            @endif
                        </dl>
                    @else
                        <div class="grid gap-4 md:grid-cols-2">
                            @foreach ([['nomorSiswa', 'Nomor surat siswa', $siswaParts, $lastSiswa, true], ['nomorGuru', 'Nomor surat guru pendamping', $guruParts, $lastGuru, $hasMentor]] as [$field, $label, $parts, $last, $enabled])
                                @if ($revising && $field !== $revisingField)
                                    @continue
                                @elseif ($enabled)
                                    <x-sth.field :for="'f-'.$field" :label="$label" :hint="'Terakhir dipakai: '.($last ?? 'belum ada').'.'" :error="$errors->first($field)">
                                        <div @class(['flex items-stretch overflow-hidden rounded-control border bg-surface', 'border-red' => $errors->has($field), 'border-line-input' => ! $errors->has($field)])>
                                            <span class="num inline-flex items-center bg-subtle px-2.5 text-sm font-semibold text-ink-2">{{ $parts[0] }}</span>
                                            <input id="f-{{ $field }}" type="text" inputmode="numeric" maxlength="5" autocomplete="off"
                                                   class="num min-w-0 flex-1 border-0 px-2.5 py-2.5 text-[15px] font-bold outline-none focus-visible:bg-primary-wash"
                                                   wire:model.live.debounce.400ms="{{ $field }}" placeholder="313"
                                                   aria-describedby="f-{{ $field }}-hint @error($field) f-{{ $field }}-error @enderror"
                                                   @error($field) aria-invalid="true" @enderror>
                                            <span class="num inline-flex items-center bg-subtle px-2.5 text-sm font-semibold text-ink-2">{{ $parts[1] }}</span>
                                        </div>
                                    </x-sth.field>
                                @else
                                    <x-sth.alert tone="warning">Belum ada guru pembimbing, jadi hanya surat siswa yang dibuat.</x-sth.alert>
                                @endif
                            @endforeach
                        </div>
                    @endif

                    <x-sth.input type="date" wire:model.live.debounce.400ms="tanggalSurat" label="Tanggal surat" :disabled="$locked" class="md:max-w-60" />

                    <fieldset class="m-0 flex flex-col gap-3 border-0 p-0">
                        <legend class="mb-2 text-sm font-bold">Dasar <span class="muted font-medium">· Surat undangan dari {{ $content->organizer }}</span></legend>
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-sth.input wire:model.live.debounce.500ms="dasarNomor" label="Nomor surat undangan" optional maxlength="150" :disabled="$locked" />
                            <x-sth.input type="date" wire:model.live.debounce.400ms="dasarTanggal" label="Tanggal surat undangan" optional :disabled="$locked" />
                        </div>
                    </fieldset>

                    <fieldset class="m-0 flex flex-col gap-3 border-0 p-0">
                        <legend class="mb-2 text-sm font-bold">Pelaksanaan <span class="muted font-medium">· Diisi dari data lomba</span></legend>
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-sth.input wire:model.live.debounce.500ms="hariTanggal" label="Hari, tanggal" maxlength="150" :disabled="$locked" class="md:col-span-2" />
                            <x-sth.input wire:model.live.debounce.500ms="pukul" label="Pukul" maxlength="150" :disabled="$locked" />
                            <x-sth.input wire:model.live.debounce.500ms="tempat" label="Tempat" maxlength="150" :disabled="$locked" />
                        </div>
                    </fieldset>

                    <div class="flex items-center gap-3 rounded-tile border border-line bg-subtle p-3">
                        <span class="inline-flex h-12 w-16 shrink-0 items-center justify-center rounded-control border border-dashed border-line-dashed bg-surface text-muted" aria-hidden="true"><x-sth.icon name="image" /></span>
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            @if ($signature)
                                <span class="ellip text-sm font-bold">{{ $signature }}</span>
                                <span class="muted text-[12.5px]">Tanda tangan dan stempel dipasang otomatis di semua surat.</span>
                            @else
                                <span class="text-sm font-bold text-red">Tanda tangan belum diunggah</span>
                                <span class="muted text-[12.5px]">Minta Admin mengunggahnya di Pengaturan.</span>
                            @endif
                        </span>
                    </div>
                </x-sth.card>

                <x-sth.card as="section" class="flex flex-col gap-4" aria-labelledby="langkah-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="langkah-3" class="title-card">3. Buat PDF</h2>
                        @if ($issued)
                            <x-sth.chip tone="green" icon="check">{{ $letters->count() }} PDF siap</x-sth.chip>
                        @endif
                    </div>

                    @if ($revising)
                        {{-- B3 (#11): cabut + versi baru. --}}
                        <form wire:submit="revise" class="flex flex-col gap-3" novalidate>
                            <x-sth.textarea wire:model.live.debounce.400ms="alasan" label="Alasan pencabutan" rows="3" maxlength="500"
                                            hint="Tercatat di riwayat surat. Contoh: tanggal pelaksanaan salah ketik." />
                            <div class="flex flex-wrap items-center gap-3">
                                <x-sth.button type="submit" variant="danger" icon="file" wire:loading.attr="disabled" wire:target="revise" :disabled="! $reviseReady || ! $signature">
                                    Cabut dan terbitkan ulang
                                </x-sth.button>
                                <x-sth.button variant="quiet" wire:click="cancelRevision" wire:loading.attr="disabled">Batal</x-sth.button>
                                <span wire:loading wire:target="revise" class="muted text-[13px]">Membuat PDF…</span>
                            </div>
                            <p class="muted m-0 text-[12.5px]">
                                {{ $revising->sent_at ? 'Versi baru langsung dikirim, penerima diberi tahu.' : 'Versi baru menunggu dikirim bersama surat lain.' }}
                            </p>
                        </form>
                    @elseif (! $issued)
                        <div class="flex flex-wrap items-center gap-3">
                            <x-sth.button icon="file" wire:click="generate" wire:loading.attr="disabled" wire:target="generate" :disabled="! $ready || ! $signature">
                                Buat {{ $hasMentor ? '2 PDF' : 'PDF' }}
                            </x-sth.button>
                            <span wire:loading wire:target="generate" class="muted text-[13px]">Membuat PDF…</span>
                            <p @class(['m-0 text-[13px]', 'text-red font-semibold' => $errors->hasAny(['nomorSiswa', 'nomorGuru']), 'muted' => ! $errors->hasAny(['nomorSiswa', 'nomorGuru'])]) wire:loading.remove wire:target="generate">
                                @if ($errors->hasAny(['nomorSiswa', 'nomorGuru']))
                                    Ganti nomor yang sudah dipakai.
                                @elseif (! $ready)
                                    Isi {{ $hasMentor ? 'kedua nomor surat' : 'nomor surat' }} dulu.
                                @else
                                    Tanda tangan dan stempel ikut terpasang.
                                @endif
                            </p>
                        </div>
                    @else
                        <ul class="m-0 flex list-none flex-col gap-2 p-0">
                            @foreach ($letters as $letter)
                                <li wire:key="file-{{ $letter->id }}" class="flex flex-wrap items-center gap-3 rounded-tile border border-line p-3">
                                    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-control bg-red-bg text-red" aria-hidden="true"><x-sth.icon name="file" /></span>
                                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span class="ellip text-sm font-bold">{{ $letter->file?->original_name }}</span>
                                        <span class="muted text-[12.5px]">{{ $letter->type->label() }} · {{ \Illuminate\Support\Number::fileSize((int) $letter->file?->size, 0) }}{{ $letter->type === \App\Enums\LetterType::Siswa ? ' · '.$n.' siswa' : ' · dengan lampiran' }}</span>
                                    </span>
                                    <x-sth.button variant="quiet" size="sm" icon="download" wire:click="download('{{ $letter->id }}')">Unduh</x-sth.button>
                                    @if ($letter->file_id)
                                        <x-sth.button variant="quiet" size="sm" icon="edit" wire:click="startRevision('{{ $letter->id }}')">
                                            Koreksi<span class="sr-only"> {{ $letter->full_number }}</span>
                                        </x-sth.button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        <div class="flex flex-wrap items-center gap-3">
                            @if ($sent)
                                <x-sth.chip tone="green" icon="check" role="status">Terkirim ke {{ $n }} siswa{{ $guruNumber ? ' dan 1 guru' : '' }}</x-sth.chip>
                            @else
                                <x-sth.button icon="send" wire:click="send" wire:loading.attr="disabled" wire:target="send">Kirim ke siswa{{ $guruNumber ? ' dan guru' : '' }}</x-sth.button>
                            @endif
                            <p class="muted m-0 text-[12.5px]">{{ $n > 1 ? 'Tiap anggota tim menerima surat yang sama.' : 'Siswa dan guru pembimbing menerima surat masing-masing.' }}</p>
                        </div>
                    @endif
                </x-sth.card>

                @if ($history !== [])
                    {{-- B3 (#11): revoked versions stay on record (number never reused). --}}
                    <x-sth.card as="section" class="flex flex-col gap-3" aria-labelledby="riwayat-surat">
                        <h2 id="riwayat-surat" class="title-card">Riwayat pencabutan</h2>
                        <ul class="m-0 flex list-none flex-col gap-2 p-0">
                            @foreach ($history as $row)
                                <li wire:key="riwayat-{{ $row['id'] }}" class="flex flex-col gap-1 rounded-tile border border-line p-3">
                                    <span class="flex flex-wrap items-center gap-2">
                                        <span class="num text-sm font-bold">{{ $row['number'] }}</span>
                                        <x-sth.chip tone="red" size="sm">Dicabut</x-sth.chip>
                                        <span class="muted text-[12.5px]">{{ $row['type'] }} · versi {{ $row['version'] }}</span>
                                    </span>
                                    <span class="text-[13px] text-ink-2">{{ $row['revokedOn'] }} oleh {{ $row['by'] }}{{ $row['replacement'] ? ' · diganti '.$row['replacement'] : '' }}</span>
                                    <span class="text-[13px] text-ink-2">Alasan: {{ $row['reason'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </x-sth.card>
                @endif
            </div>

            <x-sth.card as="section" class="flex min-w-0 flex-col gap-4" aria-labelledby="langkah-2">
                <h2 id="langkah-2" class="title-card">2. Pratinjau</h2>
                <div class="seg" role="tablist" aria-label="Jenis surat">
                    <button type="button" role="tab" id="tab-siswa" aria-controls="pratinjau-surat" aria-selected="{{ $tab === 'siswa' ? 'true' : 'false' }}"
                            @class(['seg-btn', 'seg-on' => $tab === 'siswa']) wire:click="setTab('siswa')">Surat siswa (1 surat · {{ $n }} siswa)</button>
                    @if ($hasMentor)
                        <button type="button" role="tab" id="tab-guru" aria-controls="pratinjau-surat" aria-selected="{{ $tab === 'guru' ? 'true' : 'false' }}"
                                @class(['seg-btn', 'seg-on' => $tab === 'guru']) wire:click="setTab('guru')">Surat guru pendamping</button>
                    @endif
                </div>

                <div id="pratinjau-surat" role="tabpanel" aria-labelledby="{{ $tab === 'guru' && $hasMentor ? 'tab-guru' : 'tab-siswa' }}">
                    @if ($tab === 'guru' && $hasMentor)
                        @include('livewire.tata-usaha.partials.pratinjau-guru', ['number' => $guruNumber, 'parts' => $guruParts, 'seq' => $nomorGuru])
                    @else
                        @include('livewire.tata-usaha.partials.pratinjau-siswa', ['number' => $siswaNumber, 'parts' => $siswaParts, 'seq' => $nomorSiswa])
                    @endif
                </div>
            </x-sth.card>
        </div>
    @endif
</div>
