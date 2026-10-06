{{-- Drawer form "Tambah / Ubah / Periksa lomba" (board Kesiswaan-Kelola-Lomba). Rules live in the Competition Actions. --}}
<div>
    <x-sth.modal :id="\App\Livewire\Kesiswaan\Lomba\Form::DIALOG" :title="$title" variant="drawer"
        description="{{ $formMode === 'create' ? 'Lomba baru masuk antrean verifikasi dulu, termasuk yang kamu buat.' : ($submitterName ? 'Dikirim oleh '.$submitterName.'.' : null) }}">
        <form id="lomba-form-el" wire:submit="save" class="flex flex-col gap-5" novalidate>
            @if ($errors->has('decision') || $errors->has('status'))
                <x-sth.alert tone="error" data-sth-error-summary tabindex="-1">{{ $errors->first('decision') ?: $errors->first('status') }}</x-sth.alert>
            @endif

            <x-sth.input wire:model="form.name" label="Nama lomba" placeholder="Contoh: OSN Tingkat Kota Semarang 2026" maxlength="200" required />

            <x-sth.radio-pick wire:model="form.bidang" label="Bidang" :columns="4" size="sm" :options="$options['bidang']" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-sth.input wire:model="form.organizer_name" label="Penyelenggara" maxlength="200" />
                <x-sth.select wire:model="form.organizer_type" label="Jenis penyelenggara" placeholder="Pilih jenis" :options="$options['organizer_type']" />
            </div>

            <x-sth.radio-pick wire:model.live="form.weight_category" label="Kategori bobot" :columns="5" :options="$options['weight_category']"
                hint="A Puspresnas · B Pemerintah · C Federasi/PT · D Swasta · E Lainnya" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-sth.select wire:model="form.event_status" label="Status ajang" placeholder="Pilih status" :options="$options['event_status']" />
                <x-sth.segmented wire:model="form.mode" label="Pelaksanaan" block :options="$options['mode']" />
            </div>

            <x-sth.radio-pick wire:model.live="form.level" label="Tingkat" size="sm" :options="$options['level']" hint="Sesuai cakupan peserta nyata." />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-sth.input type="date" wire:model="form.registration_opens_at" label="Daftar dibuka" />
                <x-sth.input type="date" wire:model="form.registration_closes_at" label="Batas daftar" />
                <x-sth.input type="date" wire:model="form.starts_on" label="Tanggal mulai" hint="Tidak boleh sebelum batas daftar." />
                <x-sth.input type="date" wire:model="form.final_on" label="Tanggal final" hint="Dasar batas laporan hasil." />
            </div>

            {{-- Q1-04: old lomba may be published for the record, but only with this tick (VerifyCompetition asks for it). --}}
            @if ($formMode === 'verify' && ($pastDates !== [] || $errors->has('confirmPast')))
                <div class="flex flex-col gap-1 rounded-tile border border-line bg-subtle p-3">
                    <x-sth.checkbox wire:model="confirmPast"
                        :hint="$pastDates !== [] ? 'Sudah lewat: '.implode(', ', $pastDates).'. Siswa tidak bisa mendaftar lagi lewat STH.' : null">
                        Tanggal lomba ini sudah lewat. Tetap publikasikan.
                    </x-sth.checkbox>
                </div>
            @endif

            <x-sth.input wire:model="form.location" label="Tempat" placeholder="Contoh: Kota Semarang atau Daring" maxlength="200" />

            <div class="grid grid-cols-1 items-start gap-4 sm:grid-cols-[1fr_140px]">
                <x-sth.segmented wire:model.live="form.participation" label="Peserta" block :options="$options['participation']" />
                @if (($form['participation'] ?? null) !== null && $form['participation'] !== 'individu')
                    <x-sth.input type="number" wire:model="form.max_team_size" label="Maks anggota" min="2" max="20" inputmode="numeric" />
                @endif
            </div>

            <x-sth.select wire:model="form.default_mentor_id" label="Guru pembimbing default" placeholder="Belum ditentukan" :options="$options['mentors']" optional />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-sth.input type="url" wire:model="form.website_url" label="Website resmi" placeholder="https://" icon="link" optional />
                <x-sth.input type="url" wire:model="form.registration_url" label="Link registrasi" placeholder="https://" icon="link" optional />
            </div>

            <x-sth.textarea wire:model="form.description" label="Deskripsi singkat" rows="3" optional />

            <div class="flex flex-col gap-2">
                <x-sth.file-drop wire:model="juknis" label="Juknis/brosur" accept=".pdf,.jpg,.jpeg,.png"
                    :title="$juknis ? $juknis->getClientOriginalName() : ($competition?->juknisFile ? 'Ganti file' : 'Upload PDF')"
                    hint="PDF atau gambar, maks 5 MB." optional />
                @if ($competition?->juknisFile && ! $juknis)
                    <p class="m-0 text-[13px] text-muted">
                        File sekarang:
                        @if ($juknisUrl)
                            <a class="lnk" href="{{ $juknisUrl }}">{{ $competition->juknisFile->original_name }}</a>
                        @else
                            {{ $competition->juknisFile->original_name }}
                        @endif
                    </p>
                @endif
                <p wire:loading wire:target="juknis" class="m-0 text-[13px] text-muted">Meng-upload…</p>
            </div>

            <div class="flex flex-col gap-2">
                <x-sth.file-drop wire:model="cover" label="Sampul" accept=".jpg,.jpeg,.png,.webp" icon="image"
                    :title="$cover ? $cover->getClientOriginalName() : ($competition?->coverFile ? 'Ganti sampul' : 'Pilih gambar')"
                    hint="JPG atau PNG, maks 3 MB. Kosongkan untuk sampul warna bidang." optional />
                @if ($competition?->coverFile && ! $cover)
                    <x-sth.checkbox wire:model="removeCover">Hapus sampul, pakai warna bidang</x-sth.checkbox>
                @endif
                <p wire:loading wire:target="cover" class="m-0 text-[13px] text-muted">Meng-upload…</p>
            </div>

            <x-sth.card tone="gold" padding="sm" class="flex flex-col gap-2">
                <span class="label">Jika Juara I</span>
                <x-sth.rumus-poin :tingkat="$rumus['tingkat'] ?? '–'" :capaian="$rumus['capaian']" :penyelenggara="$rumus['penyelenggara'] ?? '–'" :total="$rumus['total']" result-label="Poin" />
                @if ($rumus['total'] === null)
                    <span class="text-[13px] text-muted">Pilih tingkat dan kategori untuk melihat poin.</span>
                @endif
            </x-sth.card>
        </form>

        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($formMode === 'verify')
                <x-sth.button variant="soft" type="submit" form="lomba-form-el" wire:loading.attr="disabled">Simpan</x-sth.button>
                <x-sth.button icon="check" wire:click="saveAndPublish" wire:loading.attr="disabled">Simpan & publikasikan</x-sth.button>
            @else
                <x-sth.button type="submit" form="lomba-form-el" wire:loading.attr="disabled">
                    {{ $formMode === 'create' ? 'Simpan ke antrean' : 'Simpan perubahan' }}
                </x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
