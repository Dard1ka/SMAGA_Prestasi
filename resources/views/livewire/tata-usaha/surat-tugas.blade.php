{{--
    Tata Usaha · Surat tugas (mockup TU-Surat-Tugas.dc.html; M1-A, lead 2026-10-04).
    Queue → one tab per letter (its own form + preview + Buat PDF, then Download / Cetak / Koreksi) → Kirim.
    Buttons never go grey: a failed submit lists what is wrong (data-sth-error-summary) and the page jumps there.
--}}
<div class="flex flex-col gap-[22px]">
    @if ($searching)
        {{-- Top bar search (backlog #60): the queue lists only the matching registrations. --}}
        <div class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="muted">Hasil pencarian "{{ $q }}"</span>
                <x-sth.button variant="quiet" size="sm" wire:click="$set('q', '')">Hapus pencarian</x-sth.button>
            </div>
            @if ($queue->isEmpty())
                <p class="m-0 text-sm text-muted">Tidak ada pendaftaran yang cocok. Coba nama lomba atau nama siswa lain.</p>
            @endif
        </div>
    @endif

    @if ($queue->isNotEmpty() || $registration)
        <nav aria-label="Antrean surat" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($queue as $item)
                @php
                    $key = $item->competition?->bidang?->cssKey() ?? 'sains';
                    $count = $item->members->count();
                    $isOn = $registration && $registration->id === $item->id;
                    $made = $item->letters->count();
                    $needed = $item->mentor_id !== null ? 2 : 1;
                @endphp
                <a href="{{ route('tu.surat.show', $item) }}" wire:key="q-{{ $item->id }}" @if ($isOn) aria-current="page" @endif
                   @class(['pick flex items-center gap-3 p-3 text-left no-underline', 'pick-on' => $isOn])>
                    <span class="b-{{ $key }} inline-flex size-11 shrink-0 items-center justify-center rounded-tile" aria-hidden="true"><x-sth.icon :name="$key" /></span>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="ellip text-md font-bold text-ink">{{ $item->competition?->name }}</span>
                        <span class="muted ellip text-xs">{{ $item->branch ? $item->branch.' · ' : '' }}{{ $item->isTeam() ? 'Tim '.$count.' siswa' : $count.' siswa' }}</span>
                        <span @class(['text-xs font-semibold', 'text-green' => $made === 0, 'text-blue' => $made > 0])>
                            @if ($made === 0)
                                Disetujui {{ \App\Support\StaffRoleLabel::forDecision($item->principalDecider) }} · {{ $item->principal_decided_at?->translatedFormat('j M') }}
                            @elseif ($made < $needed)
                                {{ $made }} dari {{ $needed }} PDF siap
                            @else
                                PDF siap · belum dikirim
                            @endif
                        </span>
                    </span>
                </a>
            @endforeach
        </nav>
    @endif

    @if (! $registration && $queueTotal > 0)
        {{-- A search found nothing: the sentence under "Hasil pencarian" says so; the queue itself is not empty. --}}
    @elseif (! $registration)
        <x-sth.empty-state icon="mail" title="Belum ada surat untuk dibuat">
            Pendaftaran yang sudah disetujui muncul di sini.
            @if (Route::has('tu.surat.arsip'))
                <x-slot:actions><x-sth.button variant="quiet" size="sm" href="{{ route('tu.surat.arsip') }}">Buka arsip surat</x-sth.button></x-slot:actions>
            @endif
        </x-sth.empty-state>
    @else
        @php
            $n = $content->studentCount();
            $isGuru = $type === \App\Enums\LetterType::Guru;
            $typeName = $isGuru ? 'surat guru pendamping' : 'surat siswa';
            $model = $type->value;
            $guruSent = $letters->contains(fn ($l) => $l->type === \App\Enums\LetterType::Guru);
            // D-154: the student letter's Dasar is the template's regulation texts (1 to 6 since SV8, D-195); one printed
            // before D-154 named the invitation. D-157: an invitation on a student letter printed since then is its last Dasar.
            $oldBasis = $isGuru ? null : $letterTemplate->oldSiswaBasis($content->basisFrom);
            // SV9, D-200: the guru letter's template Dasar (0 to 6 texts) is shown the same way, the invitation after it.
            $templateDasar = $isGuru ? $letterTemplate->guruBasisItems($content->schoolYear) : $letterTemplate->siswaDasarItems($content->schoolYear);
            $dasarCount = $isGuru ? count($templateDasar) : max(1, count($templateDasar));
            $dasarLabel = $dasarCount > 1 ? 'Dasar 1–'.$dasarCount : 'Dasar 1';
            $siswaBasis = $isGuru || $oldBasis !== null ? [] : $content->siswaBasis($letterTemplate);
            $basisLine = ($oldBasis ?? 'Surat dari '.$content->basisFrom)
                .($content->basisNumber ? ', nomor '.$content->basisNumber : '')
                .($content->basisDateText ? ', tanggal '.$content->basisDateText : '')
                .($isGuru && $content->basisSubject !== '' ? ', hal '.$content->basisSubject : '');
        @endphp

        <div class="flex flex-col gap-3">
            {{-- On a phone the two tabs share the width and show the state under the name: both names with "belum
                 dibuat" needed 180px more than a 390px screen (QA7). --}}
            <div class="seg self-start max-sm:grid max-sm:w-full max-sm:auto-cols-fr max-sm:grid-flow-col" role="tablist" aria-label="Pilih surat">
                @foreach ($tabs as $item)
                    <button type="button" role="tab" id="tab-{{ $item['key'] }}" aria-controls="panel-surat" wire:key="tab-{{ $item['key'] }}"
                            aria-selected="{{ $model === $item['key'] ? 'true' : 'false' }}"
                            @class(['seg-btn max-sm:h-auto max-sm:min-h-10 max-sm:flex-col max-sm:gap-0 max-sm:py-1.5 max-sm:whitespace-normal', 'seg-on' => $model === $item['key']]) wire:click="setTab('{{ $item['key'] }}')">
                        {{ $item['label'] }}
                        <span class="font-medium"><span class="max-sm:hidden">· </span>{{ $item['letter'] ? ($item['letter']->sent_at ? 'terkirim' : 'PDF siap') : 'belum dibuat' }}</span>
                    </button>
                @endforeach
            </div>
            <p class="muted m-0 text-xs">
                {{ $hasMentor ? 'Tiap surat punya nomor dan data sendiri. Buat PDF satu per satu, lalu kirim keduanya.' : 'Belum ada guru pembimbing, jadi hanya surat siswa yang dibuat.' }}
            </p>
        </div>

        <div id="panel-surat" role="tabpanel" aria-labelledby="tab-{{ $model }}" class="grid grid-cols-1 items-start gap-[22px] xl:grid-cols-[minmax(0,1fr)_minmax(0,560px)]">
            <x-sth.card as="section" class="flex min-w-0 flex-col gap-5" aria-labelledby="h-data-surat">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="h-data-surat" class="title-card">
                        {{ $revising ? 'Koreksi '.$typeName : ($editing ? '1. Isi data '.$typeName : 'Data '.$typeName) }}
                    </h2>
                    <span class="muted text-xs">
                        {{ $revising ? 'Ubah yang salah, lalu terbitkan ulang' : ($editing ? 'Semua kolom wajib, kecuali yang bertanda opsional' : 'PDF sudah dibuat') }}
                    </span>
                </div>

                @if ($editing)
                    <form id="surat-form-{{ $model }}" wire:submit="{{ $revising ? 'revise' : 'generate' }}" class="flex flex-col gap-5" novalidate>
                        @if ($problems !== [])
                            <x-sth.alert tone="error" :title="$revising ? 'Surat belum bisa dicabut' : 'PDF belum bisa dibuat'" data-sth-error-summary tabindex="-1">
                                <p class="m-0">Perbaiki dulu, lalu tekan tombolnya lagi:</p>
                                <ul class="m-0 mt-1 flex list-disc flex-col gap-0.5 pl-5">
                                    @foreach ($problems as [$label, $message])
                                        <li wire:key="masalah-{{ $loop->index }}">@if ($label)<span class="font-semibold">{{ $label }}:</span> @endif{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </x-sth.alert>
                        @endif

                        {{-- SV6, D-188: no warning box on a Koreksi; every field starts with what the old letter printed and
                             stays tinted blue while it is unchanged. --}}
                        @if ($sameLine)
                            <p class="m-0 flex items-center gap-2 text-sm">
                                <span class="inline-block size-4 shrink-0 rounded-control border border-line bg-blue-bg" aria-hidden="true"></span>
                                {{ $sameLine }}
                            </p>
                        @endif

                        @unless ($signature)
                            <x-sth.alert tone="warning" title="Tanda tangan belum di-upload">
                                PDF belum bisa dibuat. Minta Admin upload tanda tangan dan stempel di Pengaturan › Surat & tanda tangan.
                            </x-sth.alert>
                        @endunless

                        <div class="grid grid-cols-1 items-start gap-4 md:grid-cols-2">
                            <x-sth.input wire:model.live.debounce.500ms="{{ $model }}.nomor" label="Nomor surat"
                                         placeholder="Ketik nomor lengkap" maxlength="64" autocomplete="off" class="num {{ $revising ? '' : 'md:col-span-2' }}"
                                         :input-class="$tint['nomor'] ?? null" :hint="$numberHint" />
                            @if ($revising)
                                <x-sth.textarea wire:model.live.debounce.500ms="alasan" label="Alasan pencabutan" rows="3" maxlength="500"
                                                hint="Minimal 5 karakter. Tercatat di riwayat surat. Contoh: tanggal pelaksanaan salah ketik." />
                            @endif
                            <x-sth.input type="date" wire:model.live.debounce.400ms="{{ $model }}.tanggalSurat" label="Tanggal surat" class="md:max-w-60"
                                         :input-class="$tint['tanggalSurat'] ?? null" />
                        </div>

                        @if ($isGuru)
                            {{-- SV9, D-200: like the student tab, the template's regulation texts first, the invitation as the next number. --}}
                            @if ($dasarCount > 0)
                                <div class="flex flex-col gap-1">
                                    <p class="m-0 text-sm font-bold">{{ $dasarLabel }} <span class="muted font-medium">· Dari Template surat</span></p>
                                    <ol class="m-0 flex list-decimal flex-col gap-1 pl-5 text-sm [overflow-wrap:anywhere]">
                                        @foreach ($templateDasar as $item)
                                            <li wire:key="dasar-template-{{ $loop->index }}">{{ $item }}</li>
                                        @endforeach
                                    </ol>
                                    <p class="muted m-0 text-xs">
                                        Sama untuk semua surat guru.
                                        @if (Route::has('tu.surat.template'))
                                            <a href="{{ route('tu.surat.template') }}" class="font-semibold" wire:navigate>Ubah di Template surat</a>
                                        @endif
                                    </p>
                                </div>
                            @endif
                            <fieldset class="m-0 flex flex-col gap-3 border-0 p-0">
                                <legend class="mb-2 text-sm font-bold">Dasar {{ $dasarCount + 1 }} <span class="muted font-medium">· Surat undangan lomba</span></legend>
                                <div class="grid grid-cols-1 items-start gap-4 md:grid-cols-2">
                                    <x-sth.input wire:model.live.debounce.500ms="{{ $model }}.dasarDari" :input-class="$tint['dasarDari'] ?? null" label="Surat dari" maxlength="200" class="md:col-span-2"
                                                 hint="Diisi dari penyelenggara lomba. Boleh diubah." />
                                    <x-sth.input wire:model.live.debounce.500ms="{{ $model }}.dasarNomor" :input-class="$tint['dasarNomor'] ?? null" label="Nomor surat undangan" optional maxlength="150" autocomplete="off" />
                                    <x-sth.input type="date" wire:model.live.debounce.400ms="{{ $model }}.dasarTanggal" :input-class="$tint['dasarTanggal'] ?? null" label="Tanggal surat undangan" optional />
                                    <x-sth.input wire:model.live.debounce.500ms="{{ $model }}.dasarHal" :input-class="$tint['dasarHal'] ?? null" label="Hal" maxlength="150" class="md:col-span-2" />
                                </div>
                            </fieldset>
                        @else
                            {{-- D-154: the first Dasar texts of the student letter are fixed regulation texts of the template
                                 (one or more since SV8, D-195). D-157: the invitation is optional here and, left filled,
                                 prints as the next Dasar (no "Hal"). --}}
                            <div class="flex flex-col gap-1">
                                <p class="m-0 text-sm font-bold">{{ $dasarLabel }} <span class="muted font-medium">· Dari Template surat</span></p>
                                @if ($dasarCount > 1)
                                    <ol class="m-0 flex list-decimal flex-col gap-1 pl-5 text-sm [overflow-wrap:anywhere]">
                                        @foreach ($templateDasar as $item)
                                            <li wire:key="dasar-template-{{ $loop->index }}">{{ $item }}</li>
                                        @endforeach
                                    </ol>
                                @else
                                    <p class="m-0 text-sm [overflow-wrap:anywhere]">{{ $templateDasar[0] ?? '-' }}</p>
                                @endif
                                <p class="muted m-0 text-xs">
                                    Sama untuk semua surat siswa.
                                    @if (Route::has('tu.surat.template'))
                                        <a href="{{ route('tu.surat.template') }}" class="font-semibold" wire:navigate>Ubah di Template surat</a>
                                    @endif
                                </p>
                            </div>
                            <fieldset class="m-0 flex flex-col gap-3 border-0 p-0">
                                <legend class="mb-2 text-sm font-bold">Dasar {{ $dasarCount + 1 }} <span class="muted font-medium">· Surat undangan lomba</span></legend>
                                <div class="grid grid-cols-1 items-start gap-4 md:grid-cols-2">
                                    <x-sth.input wire:model.live.debounce.500ms="{{ $model }}.dasarDari" :input-class="$tint['dasarDari'] ?? null" label="Surat dari" optional maxlength="200" class="md:col-span-2"
                                                 :hint="'Diisi dari penyelenggara lomba. Kosongkan bila tidak ada surat undangan, surat siswa lalu hanya memuat '.$dasarLabel.'.'" />
                                    <x-sth.input wire:model.live.debounce.500ms="{{ $model }}.dasarNomor" :input-class="$tint['dasarNomor'] ?? null" label="Nomor surat undangan" optional maxlength="150" autocomplete="off" />
                                    <x-sth.input type="date" wire:model.live.debounce.400ms="{{ $model }}.dasarTanggal" :input-class="$tint['dasarTanggal'] ?? null" label="Tanggal surat undangan" optional />
                                </div>
                            </fieldset>
                        @endif

                        <fieldset class="m-0 flex flex-col gap-3 border-0 p-0">
                            <legend class="mb-2 text-sm font-bold">Pelaksanaan <span class="muted font-medium">· Diisi dari data lomba</span></legend>
                            <div class="grid grid-cols-1 items-start gap-4 md:grid-cols-2">
                                <x-sth.input wire:model.live.debounce.500ms="{{ $model }}.hari" :input-class="$tint['hari'] ?? null" label="Hari" maxlength="150" />
                                <x-sth.input wire:model.live.debounce.500ms="{{ $model }}.tanggal" :input-class="$tint['tanggal'] ?? null" label="Tanggal" maxlength="150"
                                             hint="Lebih dari sehari: 7 November 2026 s.d 9 November 2026." />
                                <x-sth.input wire:model.live.debounce.500ms="{{ $model }}.pukul" :input-class="$tint['pukul'] ?? null" label="Pukul" maxlength="150" />
                                <x-sth.input wire:model.live.debounce.500ms="{{ $model }}.tempat" :input-class="$tint['tempat'] ?? null" label="Tempat" maxlength="150" />
                            </div>
                        </fieldset>

                        @if ($signature)
                            <div class="flex items-center gap-3 rounded-tile border border-line bg-subtle p-3">
                                <span class="inline-flex h-12 w-16 shrink-0 items-center justify-center rounded-control border border-dashed border-line-dashed bg-surface text-muted" aria-hidden="true"><x-sth.icon name="image" /></span>
                                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span class="ellip text-sm font-bold">{{ $signature }}</span>
                                    <span class="muted text-xs">Tanda tangan dan stempel dipasang otomatis di surat.</span>
                                </span>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center gap-3">
                            @if ($revising)
                                <x-sth.button type="submit" variant="danger" icon="file" wire:loading.attr="disabled" wire:target="revise">Cabut dan terbitkan ulang</x-sth.button>
                                <x-sth.button variant="quiet" wire:click="cancelRevision" wire:loading.attr="disabled">Batal</x-sth.button>
                                <span wire:loading wire:target="revise" class="muted text-xs">Membuat PDF…</span>
                                <p class="muted m-0 text-xs" wire:loading.remove wire:target="revise">
                                    Surat lama tercatat sebagai dicabut. {{ $revising->sent_at ? 'Versi baru langsung dikirim dan penerima diberi tahu.' : 'Versi baru ikut dikirim saat tombol Kirim ditekan.' }}
                                </p>
                            @else
                                <x-sth.button type="submit" icon="file" wire:loading.attr="disabled" wire:target="generate">Buat PDF {{ $typeName }}</x-sth.button>
                                <span wire:loading wire:target="generate" class="muted text-xs">Membuat PDF…</span>
                                <p class="muted m-0 text-xs" wire:loading.remove wire:target="generate">Setelah dibuat, nomor dan isi surat tidak bisa diubah.</p>
                            @endif
                        </div>
                    </form>
                @else
                    <dl class="m-0 grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-[160px_1fr]">
                        <dt class="text-sm font-semibold text-muted">Nomor surat</dt>
                        <dd class="num m-0 text-sm font-bold">{{ $letter->full_number }}</dd>
                        <dt class="text-sm font-semibold text-muted">Tanggal surat</dt>
                        <dd class="m-0 text-sm">{{ $content->issuedOnText }}</dd>
                        <dt class="text-sm font-semibold text-muted">Dasar</dt>
                        <dd class="m-0 text-sm">
                            {{-- D-157, SV8 D-195: a student letter lists every Dasar it printed, numbered when more than one. --}}
                            @if ($isGuru && $templateDasar !== [])
                                {{-- SV9, D-200: the guru letter's printed template texts, then its invitation --}}
                                <ol class="m-0 list-decimal pl-5">
                                    @foreach ($templateDasar as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                    <li>{{ $basisLine }}</li>
                                </ol>
                            @elseif ($isGuru || $oldBasis !== null)
                                {{ $basisLine }}
                            @elseif (count($siswaBasis) > 1)
                                <ol class="m-0 list-decimal pl-5">
                                    @foreach ($siswaBasis as $item)
                                        <li>{{ $item }}</li>
                                    @endforeach
                                </ol>
                            @else
                                {{ $siswaBasis[0] ?? '-' }}
                            @endif
                        </dd>
                        <dt class="text-sm font-semibold text-muted">Pelaksanaan</dt>
                        <dd class="m-0 text-sm">{{ $content->scheduleDay }}, {{ $content->scheduleDate }} · {{ $content->scheduleTime }} · {{ $content->place }}</dd>
                    </dl>

                    <div class="flex flex-wrap items-center gap-3 rounded-tile border border-line p-3">
                        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-control bg-red-bg text-red" aria-hidden="true"><x-sth.icon name="file" /></span>
                        {{-- min-w-48: the three buttons wrap to their own row instead of squeezing the file name to nothing. --}}
                        <span class="flex min-w-48 flex-1 flex-col gap-0.5">
                            <span class="text-sm font-bold [overflow-wrap:anywhere]">{{ $letter->file?->original_name }}</span>
                            <span class="muted text-xs">{{ $letter->type->label() }}{{ $letter->file !== null ? ' · '.\App\Support\FileSizeLabel::format((int) $letter->file->size) : '' }}{{ $isGuru ? ($letterTemplate->guruLayout === \App\Actions\Templates\LetterTemplate::GURU_LAYOUT_LAMPIRAN ? ' · dengan lampiran' : '') : ' · '.$n.' siswa' }}</span>
                        </span>
                        @if ($letter->file_id)
                          <div class="flex flex-wrap gap-2">
                            <x-sth.button variant="quiet" size="sm" icon="download" wire:click="download('{{ $letter->id }}')">Download</x-sth.button>
                            <x-sth.button variant="quiet" size="sm" icon="external" href="{{ route('tu.surat.cetak', $letter) }}" target="_blank" rel="noopener" :navigate="false">
                                Cetak<span class="sr-only"> {{ $letter->full_number }}, buka di tab baru</span>
                            </x-sth.button>
                            <x-sth.button variant="quiet" size="sm" icon="edit" wire:click="startRevision('{{ $letter->id }}')">
                                Koreksi<span class="sr-only"> {{ $letter->full_number }}</span>
                            </x-sth.button>
                          </div>
                        @endif
                    </div>

                    <p class="muted m-0 text-xs">
                        Cetak membuka PDF di tab baru, lalu print dari sana. Nomor dan isi surat tidak bisa diubah: kalau ada yang salah, pakai Koreksi (surat lama dicabut, diganti versi baru).
                    </p>

                    @if ($revisingOther)
                        <x-sth.alert tone="warning">Koreksi {{ $revisingOther->full_number }} belum selesai. Buka tab suratnya untuk melanjutkan atau membatalkan.</x-sth.alert>
                    @endif
                @endif
            </x-sth.card>

            <x-sth.card as="section" class="flex min-w-0 flex-col gap-4" aria-labelledby="h-preview">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="h-preview" class="title-card">{{ $editing && ! $revising ? '2. Preview '.$typeName : 'Preview '.$typeName }}</h2>
                    <span class="muted text-xs">{{ $editing ? 'Berubah saat data diisi' : ($printed ? 'Isi PDF yang sudah dibuat' : 'Menurut data sekarang, bisa beda dari PDF. Lihat isi persisnya lewat Cetak.') }}</span>
                </div>
                <div wire:loading.class="opacity-70">
                    @if ($isGuru)
                        @include('livewire.tata-usaha.partials.pratinjau-guru')
                    @else
                        @include('livewire.tata-usaha.partials.pratinjau-siswa')
                    @endif
                </div>
            </x-sth.card>
        </div>

        <x-sth.card as="section" id="surat-kirim" class="flex flex-col gap-4" aria-labelledby="h-kirim">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="h-kirim" class="title-card">{{ $hasMentor ? '3. Kirim ke siswa dan guru' : '3. Kirim ke siswa' }}</h2>
                @if ($sent)
                    <x-sth.chip tone="green" icon="check" role="status">Terkirim ke {{ $n }} siswa{{ $guruSent ? ' dan 1 guru' : '' }}</x-sth.chip>
                @endif
            </div>

            @error('kirim')
                <x-sth.alert tone="error" data-sth-error-summary tabindex="-1">{{ $message }}</x-sth.alert>
            @enderror

            <ul class="m-0 flex list-none flex-col gap-2 p-0">
                @foreach ($tabs as $item)
                    <li wire:key="kirim-{{ $item['key'] }}" class="flex flex-wrap items-center gap-3 rounded-tile border border-line p-3">
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="text-sm font-bold">{{ $item['label'] }}</span>
                            <span class="num muted text-xs">{{ $item['letter'] ? 'Nomor '.$item['letter']->full_number : 'PDF belum dibuat' }}</span>
                        </span>
                        @if (! $item['letter'])
                            <x-sth.chip tone="orange" icon="clock" size="sm">Belum dibuat</x-sth.chip>
                            @if ($model !== $item['key'])
                                <x-sth.button variant="quiet" size="sm" wire:click="setTab('{{ $item['key'] }}')">Buat sekarang</x-sth.button>
                            @endif
                        @elseif ($item['letter']->sent_at)
                            <x-sth.chip tone="green" icon="check" size="sm">Terkirim</x-sth.chip>
                        @else
                            <x-sth.chip tone="blue" icon="check" size="sm">PDF siap</x-sth.chip>
                        @endif
                    </li>
                @endforeach
            </ul>

            @unless ($sent)
                <div class="flex flex-wrap items-center gap-3">
                    @if ($missing !== [])
                        {{-- A letter is missing: the click names it at once (the Action refuses), no question first. --}}
                        <x-sth.button icon="send" wire:click="send" wire:loading.attr="disabled" wire:target="send">Kirim ke siswa{{ $hasMentor ? ' dan guru' : '' }}</x-sth.button>
                    @else
                        {{-- QA6 (Q1-10): sending cannot be taken back, so it asks first. --}}
                        <x-sth.button icon="send" :data-sth-open="\App\Livewire\TataUsaha\SuratTugas::SEND_DIALOG" aria-haspopup="dialog">Kirim ke siswa{{ $hasMentor ? ' dan guru' : '' }}</x-sth.button>
                    @endif
                    @if ($missing !== [])
                        <p class="m-0 text-xs font-semibold text-orange">Belum bisa dikirim: buat dulu PDF {{ implode(' dan ', $missing) }}.</p>
                    @else
                        <p class="muted m-0 text-xs">{{ $n > 1 ? 'Tiap anggota tim menerima surat yang sama.' : 'Siswa dan guru pembimbing menerima surat masing-masing.' }}</p>
                    @endif
                </div>

                <x-sth.modal :id="\App\Livewire\TataUsaha\SuratTugas::SEND_DIALOG" title="Kirim surat sekarang?" size="sm"
                             :description="$sendNotice.' Setelah terkirim, perubahan hanya lewat Koreksi: surat lama dicabut dan diganti versi baru.'">
                    <ul class="m-0 flex list-none flex-col gap-2 p-0">
                        @foreach ($tabs as $item)
                            <li wire:key="kirim-cek-{{ $item['key'] }}" class="flex flex-col gap-0.5 rounded-tile border border-line p-3">
                                <span class="text-sm font-bold">{{ $item['label'] }}</span>
                                <span class="num muted text-xs">{{ $item['letter'] ? 'Nomor '.$item['letter']->full_number : 'PDF belum dibuat' }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <x-slot:footer>
                        <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
                        <x-sth.button icon="send" wire:click="send" wire:loading.attr="disabled" wire:target="send">Kirim sekarang</x-sth.button>
                    </x-slot:footer>
                </x-sth.modal>
            @endunless
        </x-sth.card>

        @if ($canCancel)
            {{-- D-155: Tata Usaha takes an approved registration out of the queue by cancelling it, with a reason. --}}
            <x-sth.card as="section" class="flex flex-col gap-3" aria-labelledby="h-hapus-antrean">
                <h2 id="h-hapus-antrean" class="title-card">Hapus dari antrean</h2>
                <p class="muted m-0 text-sm">Untuk pendaftaran yang tidak jadi dibuatkan surat tugas. Pendaftarannya dibatalkan dengan alasan dan surat yang sudah dibuat dicabut.</p>
                <div>
                    <x-sth.button variant="danger" icon="trash" wire:click="askCancel" wire:loading.attr="disabled" wire:target="askCancel" aria-haspopup="dialog">Hapus dari antrean</x-sth.button>
                </div>
            </x-sth.card>

            <x-sth.modal :id="\App\Livewire\TataUsaha\SuratTugas::CANCEL_DIALOG" title="Hapus dari antrean surat?" size="sm" on-close="forgetCancel"
                         :description="$cancelNotice">
                <x-sth.textarea wire:model="cancelNote" label="Alasan pembatalan" rows="3" maxlength="500"
                                hint="Minimal 5 karakter. Tampil di halaman Lomba saya siswa." />
                <x-slot:footer>
                    <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
                    <x-sth.button variant="danger" icon="trash" wire:click="cancelQueued" wire:loading.attr="disabled" wire:target="cancelQueued">Hapus dari antrean</x-sth.button>
                </x-slot:footer>
            </x-sth.modal>
        @endif

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
                                <span class="muted text-xs">{{ $row['type'] }} · versi {{ $row['version'] }}</span>
                            </span>
                            <span class="text-xs text-ink-2">{{ $row['revokedOn'] }} oleh {{ $row['by'] }}{{ $row['replacement'] ? ' · diganti '.$row['replacement'] : '' }}</span>
                            <span class="text-xs text-ink-2">Alasan: {{ $row['reason'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-sth.card>
        @endif
    @endif
</div>
