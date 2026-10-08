{{--
    Laporkan hasil (UI-MAP #13). Full page: layouts::mobile with a sticky "Kirim laporan" bar (slot `action`).
    Inline ($inline): the same form inside the Lomba saya detail pane (desktop). Data from LaporHasil::render().
--}}
@php($formId = $inline ? 'lapor-form-inline' : 'lapor-form')
@php($h = $inline ? 'h4' : 'h2')
<div class="flex flex-col gap-4">
    @if (! $inline && $deadline)
        <p @class(['m-0 inline-flex items-center gap-2 text-sm font-semibold', 'text-orange' => ! ($deadline['overdue'] && $marksLate), 'text-red' => $deadline['overdue'] && $marksLate])>
            <x-sth.icon name="clock" :size="18" />
            @if (! $deadline['overdue'])
                {{ 'Batas '.$deadline['dueText'].' ('.$deadline['days'].' hari setelah final).' }}
            @elseif ($marksLate)
                {{ 'Batas '.$deadline['dueText'].' sudah lewat. Laporan tetap diterima, tetapi ditandai terlambat.' }}
            @else
                {{ 'Batas '.$deadline['dueText'].' sudah lewat, tetapi laporan pertama dikirim tepat waktu, jadi revisi ini tidak ditandai terlambat.' }}
            @endif
        </p>
    @endif

    @if ($revision)
        <x-sth.alert tone="warning" title="Perlu revisi">
            {{ $revision->note ?: 'Kesiswaan meminta kamu memperbaiki laporan.' }}
            File yang tidak kamu ganti tetap dipakai.
        </x-sth.alert>
    @endif

    <form id="{{ $formId }}" wire:submit="kirim" class="flex flex-col gap-4" novalidate>
        {{-- Hasil + perkiraan poin --}}
        <section aria-labelledby="{{ $formId }}-hasil" @class(['flex flex-col gap-4', 'card p-5' => ! $inline])>
            <{{ $h }} id="{{ $formId }}-hasil" class="title-card">Hasil yang diraih</{{ $h }}>
            <x-sth.radio-pick wire:model.live="hasil" label="Hasil yang diraih" hide-label :options="$resultOptions" :columns="$inline ? 5 : 2" :phone-columns="2" size="sm" />

            <div class="flex flex-col gap-2">
                <p class="label m-0">Perkiraan poin <span class="muted font-medium">(final setelah diverifikasi)</span></p>
                <x-sth.rumus-poin
                    :tingkat="$base?->levelWeight"
                    :capaian="$preview?->resultWeight"
                    :penyelenggara="$base?->organizerWeight"
                    :total="$preview?->points"
                    :result-label="$inline ? 'Total' : 'Perkiraan poin kamu'" />
                @if ($isTeam && ! $inline)
                    <p class="m-0 inline-flex items-center gap-2 text-sm text-ink-2"><x-sth.icon name="users" :size="18" />Setiap anggota tim mendapat poin penuh.</p>
                @endif
            </div>
        </section>

        {{-- Bukti --}}
        <section aria-labelledby="{{ $formId }}-bukti" @class(['flex flex-col gap-4', 'card p-5' => ! $inline])>
            <{{ $h }} id="{{ $formId }}-bukti" class="title-card">Bukti</{{ $h }}>

            <div class="grid gap-4 @if ($inline) lg:grid-cols-2 @endif">
                <div class="flex flex-col gap-2">
                    @if ($sertifikat)
                        <span class="label">Sertifikat/piagam</span>
                        @include('livewire.siswa.lomba-saya.partials.upload-row', ['name' => $sertifikat->getClientOriginalName(), 'size' => $sertifikat->getSize(), 'remove' => "removeFile('sertifikat')", 'removeLabel' => 'Hapus sertifikat'])
                    @else
                        <x-sth.file-drop wire:model="sertifikat" :file="$sertifikat" id="{{ $formId }}-sertifikat" label="Sertifikat/piagam" title="Upload sertifikat"
                            hint="PDF, JPG, atau PNG · maks. 5 MB" accept=".pdf,.jpg,.jpeg,.png,.webp" :optional="isset($existing['sertifikat'])" />
                    @endif
                    @isset($existing['sertifikat'])
                        <p class="field-hint m-0">Sudah dikirim: {{ implode(', ', $existing['sertifikat']) }}</p>
                    @endisset
                    @if ($sertifikat)
                        @error('sertifikat')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                    @endif
                </div>

                <div class="flex flex-col gap-2">
                    <span class="label">Foto dokumentasi <span class="muted font-medium">· <span class="num">{{ count($foto) }}</span>/{{ \App\Actions\Reports\SubmitReport::MAX_PHOTOS }}</span></span>
                    @foreach ($foto as $i => $photo)
                        <div wire:key="foto-{{ $i }}-{{ $photo->getFilename() }}">
                            @include('livewire.siswa.lomba-saya.partials.upload-row', ['name' => $photo->getClientOriginalName(), 'size' => $photo->getSize(), 'remove' => "removeFoto({$i})", 'removeLabel' => 'Hapus foto '.($i + 1), 'icon' => 'image'])
                        </div>
                    @endforeach
                    @if (count($foto) < \App\Actions\Reports\SubmitReport::MAX_PHOTOS)
                        <x-sth.file-drop wire:model="fotoBaru" :file="$fotoBaru" id="{{ $formId }}-foto" multiple icon="image" title="Tambah foto"
                            hint="JPG atau PNG · maks. 5 MB per foto" accept=".jpg,.jpeg,.png,.webp" />
                    @endif
                    @isset($existing['foto'])
                        <p class="field-hint m-0">Sudah dikirim: {{ count($existing['foto']) }} foto. Foto baru menggantikan semuanya.</p>
                    @endisset
                    @error('foto')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                    @error('foto.*')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex flex-col gap-2">
                @if ($pengumuman)
                    <span class="label">Pengumuman resmi</span>
                    @include('livewire.siswa.lomba-saya.partials.upload-row', ['name' => $pengumuman->getClientOriginalName(), 'size' => $pengumuman->getSize(), 'remove' => "removeFile('pengumuman')", 'removeLabel' => 'Hapus pengumuman'])
                    @error('pengumuman')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                @else
                    <x-sth.file-drop wire:model="pengumuman" :file="$pengumuman" id="{{ $formId }}-pengumuman" label="Pengumuman resmi" title="Upload pengumuman"
                        hint="Tangkapan layar atau PDF · maks. 5 MB" accept=".pdf,.jpg,.jpeg,.png,.webp" optional />
                @endif
            </div>
        </section>

        @if ($isRiset)
            <section aria-labelledby="{{ $formId }}-riset" @class(['flex flex-col gap-4', 'card p-5' => ! $inline])>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <{{ $h }} id="{{ $formId }}-riset" class="title-card">Khusus lomba riset</{{ $h }}>
                    <x-sth.chip bidang="riset" />
                </div>
                <x-sth.input wire:model.blur="judul" id="{{ $formId }}-judul" label="Judul penelitian" maxlength="200" />
                <x-sth.textarea wire:model.live.debounce.500ms="abstrak" id="{{ $formId }}-abstrak" label="Abstrak" rows="5"
                    hint="{{ $abstractWords }}/{{ \App\Actions\Reports\SubmitReport::MAX_ABSTRACT_WORDS }} kata" />

                <div class="flex flex-col gap-2">
                    @if ($laporan)
                        <span class="label">Laporan penelitian</span>
                        @include('livewire.siswa.lomba-saya.partials.upload-row', ['name' => $laporan->getClientOriginalName(), 'size' => $laporan->getSize(), 'remove' => "removeFile('laporan')", 'removeLabel' => 'Hapus laporan penelitian'])
                        @error('laporan')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                    @else
                        <x-sth.file-drop wire:model="laporan" :file="$laporan" id="{{ $formId }}-laporan" label="Laporan penelitian" title="Upload laporan"
                            hint="PDF · maks. 10 MB" accept=".pdf" icon="file" :optional="isset($existing['laporan_riset'])" />
                    @endif
                    @isset($existing['laporan_riset'])
                        <p class="field-hint m-0">Sudah dikirim: {{ implode(', ', $existing['laporan_riset']) }}</p>
                    @endisset
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="flex flex-col gap-2">
                        @if ($artikel)
                            <span class="label">Artikel ilmiah</span>
                            @include('livewire.siswa.lomba-saya.partials.upload-row', ['name' => $artikel->getClientOriginalName(), 'size' => $artikel->getSize(), 'remove' => "removeFile('artikel')", 'removeLabel' => 'Hapus artikel'])
                            @error('artikel')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                        @else
                            <x-sth.file-drop wire:model="artikel" :file="$artikel" id="{{ $formId }}-artikel" label="Artikel ilmiah" title="Upload artikel"
                                hint="PDF · maks. 10 MB" accept=".pdf" icon="file" optional />
                        @endif
                    </div>
                    <div class="flex flex-col gap-2">
                        @if ($poster)
                            <span class="label">Poster</span>
                            @include('livewire.siswa.lomba-saya.partials.upload-row', ['name' => $poster->getClientOriginalName(), 'size' => $poster->getSize(), 'remove' => "removeFile('poster')", 'removeLabel' => 'Hapus poster'])
                            @error('poster')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                        @else
                            <x-sth.file-drop wire:model="poster" :file="$poster" id="{{ $formId }}-poster" label="Poster" title="Upload poster"
                                hint="JPG atau PDF · maks. 5 MB" accept=".pdf,.jpg,.jpeg,.png,.webp" icon="image" optional />
                        @endif
                    </div>
                </div>
            </section>
        @endif

        <div class="flex flex-col gap-3 @if ($inline) sm:flex-row sm:items-center sm:justify-between @endif">
            <x-sth.checkbox wire:model="asli" id="{{ $formId }}-asli">Dokumen ini asli dan sesuai hasil lomba.</x-sth.checkbox>
            @error('hasil')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
            @if ($inline)
                <x-sth.button type="submit" icon="send" wire:loading.attr="disabled" wire:target="kirim">Kirim laporan</x-sth.button>
            @endif
        </div>
    </form>

    @if (! $inline)
        <x-slot:action>
            <x-sth.button type="submit" form="lapor-form" size="lg" block>Kirim laporan</x-sth.button>
            <p class="muted m-0 text-center text-xs">Kesiswaan akan memverifikasi laporanmu.</p>
        </x-slot:action>
    @endif
</div>
