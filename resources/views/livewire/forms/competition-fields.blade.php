{{-- Every field of a lomba (App\Livewire\Forms\CompetitionFields, D-145…D-147), drawn the same way on Siswa › Usulkan lomba
     ($staff = false) and in the Kelola lomba drawer ($staff = true). Binds only `form.*`, which both components have; the
     drawer keeps its own controls (the tick for past dates) in its own view. Needs $options, $weightLine, $mapped,
     $overridden and $rumus (CompetitionFields::presentation()); the drawer also passes $competition, $juknisUrl and
     $mentorHint. --}}
<div class="flex flex-col gap-6">
    <section aria-labelledby="h-lf-lomba" class="flex flex-col gap-4">
        <h3 id="h-lf-lomba" class="title-card m-0">Lomba</h3>
        <x-sth.input wire:model="form.name" label="Nama lomba" placeholder="{{ $staff ? 'Contoh: OSN Tingkat Kota Semarang 2026' : 'Contoh: ISPO 2027' }}" maxlength="200" required />
        <x-sth.radio-pick wire:model="form.bidang" label="Bidang" :columns="4" size="sm" :options="$options['bidang']" />
        <x-sth.radio-pick wire:model.live="form.level" label="Tingkat" size="sm" :options="$options['level']" hint="Sesuai cakupan peserta nyata." />
    </section>

    <section aria-labelledby="h-lf-penyelenggara" class="flex flex-col gap-4">
        <h3 id="h-lf-penyelenggara" class="title-card m-0">Penyelenggara</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-sth.input wire:model="form.organizer_name" label="Nama penyelenggara" placeholder="Nama lembaga penyelenggara" maxlength="200" />
            <x-sth.select wire:model.live="form.organizer_type" label="Jenis penyelenggara" placeholder="Pilih jenis" :options="$options['organizer_type']" />
            {{-- "Lainnya" alone says nothing: the kind is typed here (D-125). Required before publishing, so not marked
                 optional; another type drops the text on save (the Action). --}}
            @if ($form->showsOtherType())
                <x-sth.input wire:model="form.organizer_type_other" label="Jenis penyelenggara lainnya" maxlength="60" class="sm:col-start-2"
                    placeholder="Contoh: komunitas, sanggar seni, media"
                    hint="Tulis jenisnya dengan singkat. Poin tetap mengikuti kategori bobot." />
            @endif
        </div>
        <x-sth.checkbox wire:model.live="form.puspresnas" hint="Centang bila lomba ini termasuk ajang Puspresnas.">Ajang Puspresnas</x-sth.checkbox>

        @if ($staff)
            {{-- The map (D-146) next to what is chosen: Kesiswaan may choose another weight, audited when it decides. --}}
            @if ($mapped)
                <p class="m-0 text-sm">Hasil peta: <span class="font-semibold">{{ $mapped }}</span></p>
            @endif
            <x-sth.radio-pick wire:model.live="form.weight_category" label="Kategori bobot" :columns="5" :options="$options['weight_category']"
                hint="A Puspresnas · B Pemerintah/BUMN · C Federasi/PT · D Swasta · E Lainnya" />
            <x-sth.select wire:model.live="form.event_status" label="Status ajang" placeholder="Pilih status" :options="$options['event_status']" />
            @if ($overridden)
                <p class="muted m-0 text-sm">Diubah dari hasil peta.</p>
            @endif
        @elseif ($weightLine)
            <div class="flex flex-col gap-0.5">
                <p class="m-0 text-sm font-semibold">{{ $weightLine }}</p>
                <p class="m-0 text-xs text-muted">Dihitung dari jenis penyelenggara. Kesiswaan memeriksanya sebelum lomba tampil di katalog.</p>
            </div>
        @endif
    </section>

    <section aria-labelledby="h-lf-pendaftaran" class="flex flex-col gap-4">
        <h3 id="h-lf-pendaftaran" class="title-card m-0">Pendaftaran</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-sth.input type="date" wire:model="form.registration_opens_at" label="Pendaftaran dibuka" :optional="! $staff" />
            <x-sth.input type="date" wire:model="form.registration_closes_at" label="Pendaftaran ditutup" />
        </div>
    </section>

    <section aria-labelledby="h-lf-pelaksanaan" class="flex flex-col gap-4">
        <h3 id="h-lf-pelaksanaan" class="title-card m-0">Pelaksanaan</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-sth.segmented wire:model="form.mode" label="Cara pelaksanaan" block :options="$options['mode']" />
            <x-sth.input wire:model="form.location" label="Tempat" placeholder="Contoh: Kota Semarang atau Daring" maxlength="200" />
        </div>
        <x-sth.segmented wire:model.live="form.duration" label="Durasi lomba" block :options="$options['duration']" />
        @if ($form->isOneDay())
            <x-sth.input type="date" wire:model="form.final_on" label="Tanggal lomba"
                :hint="'Tidak boleh sebelum pendaftaran ditutup.'.($staff ? ' Laporan hasil dibuka setelah tanggal ini.' : '')" />
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-sth.input type="date" wire:model="form.starts_on" label="Tanggal mulai" hint="Tidak boleh sebelum pendaftaran ditutup." />
                <x-sth.input type="date" wire:model="form.final_on" label="Tanggal selesai" :hint="$staff ? 'Laporan hasil dibuka setelah tanggal ini.' : null" />
            </div>
        @endif
    </section>

    <section aria-labelledby="h-lf-peserta" class="flex flex-col gap-4">
        <h3 id="h-lf-peserta" class="title-card m-0">Peserta</h3>
        <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-[1fr_140px]">
            <x-sth.segmented wire:model.live="form.participation" label="Peserta" block :options="$options['participation']" />
            @if ($form->allowsTeams())
                <x-sth.input type="number" wire:model="form.max_team_size" label="Maks anggota" min="2" max="20" inputmode="numeric" />
            @endif
        </div>
        @if ($staff)
            <x-sth.select wire:model.live="form.default_mentor_id" label="Guru pembimbing default" placeholder="Belum ditentukan" :options="$options['mentors']" :hint="$mentorHint" optional />
        @endif
    </section>

    <section aria-labelledby="h-lf-link" class="flex flex-col gap-4">
        <h3 id="h-lf-link" class="title-card m-0">Link</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($staff)
                <x-sth.input type="url" wire:model="form.website_url" label="Website resmi" placeholder="https://" icon="link" hint="Diawali https://."
                    maxlength="500" autocomplete="off" autocapitalize="off" spellcheck="false" optional />
            @else
                {{-- Checked on "Simpan" only (ExternalUrl says what is wrong); the server strips spaces around a pasted link. --}}
                <x-sth.input type="url" inputmode="url" wire:model="form.website_url" label="Link resmi" placeholder="https://" icon="link"
                    hint="Salin dari kolom alamat browser, diawali https://. Kesiswaan memeriksa link ini dulu."
                    maxlength="500" autocomplete="off" autocapitalize="off" spellcheck="false" />
            @endif
            <x-sth.input type="url" wire:model="form.registration_url" label="Link registrasi" placeholder="https://" icon="link" hint="Diawali https://."
                maxlength="500" autocomplete="off" autocapitalize="off" spellcheck="false" optional />
        </div>
    </section>

    <section aria-labelledby="h-lf-berkas" class="flex flex-col gap-4">
        <h3 id="h-lf-berkas" class="title-card m-0">Berkas</h3>
        <div class="flex flex-col gap-2">
            <x-sth.file-drop wire:model="form.juknis" :file="$form->juknis" label="Juknis / brosur / undangan" accept=".pdf,.jpg,.jpeg,.png,.webp"
                :title="$staff && $competition?->juknisFile ? 'Ganti file' : 'Pilih file PDF atau gambar'"
                hint="PDF, JPG, PNG, atau WEBP, maks 5 MB." />
            @if ($staff && $competition?->juknisFile && ! $form->juknis)
                <p class="m-0 text-xs text-muted">
                    File sekarang:
                    @if ($juknisUrl)
                        <a class="lnk" href="{{ $juknisUrl }}">{{ $competition->juknisFile->original_name }}</a>
                    @else
                        {{ $competition->juknisFile->original_name }}
                    @endif
                </p>
            @endif
            <p wire:loading wire:target="form.juknis" class="m-0 text-xs text-muted">Meng-upload…</p>
        </div>

        <div class="flex flex-col gap-2">
            <x-sth.file-drop wire:model="form.cover" :file="$form->cover" label="Poster" accept=".jpg,.jpeg,.png,.webp" icon="image"
                :title="$staff && $competition?->coverFile ? 'Ganti poster' : 'Pilih gambar poster'"
                hint="JPG, PNG, atau WEBP, maks 3 MB. Paling pas poster tegak (portrait), misalnya A4 atau 4:5. Tanpa poster, sampul memakai gambar bidang." optional />
            @if ($staff && $competition?->coverFile && ! $form->cover)
                <x-sth.checkbox wire:model.live="form.remove_cover">Hapus poster, pakai gambar bidang</x-sth.checkbox>
            @endif
            <p wire:loading wire:target="form.cover" class="m-0 text-xs text-muted">Meng-upload…</p>
            @if ($staff && $competition)
                {{-- What the catalog card shows for this lomba now, in the card's own 3:4 box: its stored poster
                     (the box may cut a little of the sides or bottom), else the drawing of its bidang. --}}
                <div class="flex flex-col gap-1.5">
                    <span class="text-xs font-semibold text-muted">Sampul di katalog sekarang</span>
                    <x-sth.sampul :bidang="$form->bidang" :seed="$competition->getKey()" bentuk="tegak"
                                  :src="$form->remove_cover ? null : \App\Support\Sampul::poster($competition)"
                                  class="w-[136px] rounded-tile border border-line" />
                </div>
            @endif
        </div>
    </section>

    <section aria-labelledby="h-lf-catatan" class="flex flex-col gap-4">
        <h3 id="h-lf-catatan" class="title-card m-0">Catatan</h3>
        @if ($staff)
            <x-sth.textarea wire:model="form.description" label="Deskripsi singkat" rows="3" optional />
        @else
            <x-sth.textarea wire:model="form.description" label="Catatan" rows="3" maxlength="5000"
                placeholder="Misalnya jadwal atau biaya pendaftaran" optional />
        @endif
    </section>

    {{-- Shown once a tingkat is chosen; Blade never calculates: the numbers come from CompetitionFields::rumus(). --}}
    @if ($form->level)
        <x-sth.card tone="gold" padding="sm" class="flex flex-col gap-2">
            <span class="label">Perkiraan poin jika Juara I</span>
            <x-sth.rumus-poin :tingkat="$rumus['tingkat'] ?? '–'" :capaian="$rumus['capaian']" :penyelenggara="$rumus['penyelenggara'] ?? '–'"
                :total="$rumus['total']" result-label="Poin" label="Perkiraan poin jika Juara I" />
            @if ($rumus['total'] === null)
                <span class="text-xs text-muted">{{ $staff ? 'Pilih kategori bobot untuk melihat poin.' : 'Pilih jenis penyelenggara untuk melihat poin.' }}</span>
            @endif
        </x-sth.card>
    @endif
</div>
