{{-- Talenta siswa (board Kesiswaan-Talenta). Data prepared in App\Livewire\Kesiswaan\Talenta. --}}
<div class="flex flex-col gap-[22px]">
    @include('livewire.partials.resume-download')
    <section aria-label="Ringkasan tahap" class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-sth.stat-tile icon="inbox" tone="gray" label="Terdaftar" :value="(string) $counts['terdaftar']" note="belum dipetakan" />
        <x-sth.stat-tile icon="users" tone="blue" label="Pemetaan" :value="(string) $counts['pemetaan']" note="sedang dipetakan" />
        <x-sth.stat-tile icon="clock" tone="orange" label="Seleksi/asesmen" :value="(string) $counts['seleksi']" note="menunggu hasil" />
        <x-sth.stat-tile icon="award" tone="green" label="Peserta pembinaan" :value="(string) $counts['pembinaan']" note="siap dibina" />
    </section>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="seg flex-wrap" role="group" aria-label="Tahap">
            @foreach (['semua' => 'Semua', 'terdaftar' => 'Terdaftar', 'pemetaan' => 'Pemetaan', 'seleksi' => 'Seleksi', 'pembinaan' => 'Peserta pembinaan', 'tidak_lolos' => 'Tidak lolos'] as $key => $label)
                <button type="button" class="seg-btn" wire:click="setTahap('{{ $key }}')" aria-pressed="{{ $tahap === $key ? 'true' : 'false' }}">
                    {{ $label }} <span class="cnt">{{ $counts[$key] ?? 0 }}</span>
                </button>
            @endforeach
        </div>
        {{-- Opens the dialog at the end of this file: which statuses go into the file (M3-A). --}}
        <x-sth.button variant="quiet" size="sm" icon="download" wire:click="askExport" aria-haspopup="dialog">Download data (Excel)</x-sth.button>
    </div>

    {{-- QA5: at 1366 px (the school laptops) the panel is slimmer and the table drops the avatar and narrows its
         fixed columns, so the student's name stays readable next to the open panel. --}}
    <div class="grid grid-cols-1 items-start gap-[22px] xl:grid-cols-[minmax(0,1fr)_300px] 2xl:grid-cols-[minmax(0,1fr)_392px]">
        <x-sth.card padding="none" class="flex min-w-0 flex-col">
            <div class="flex flex-col gap-3 border-b border-line p-4">
                <div class="flex flex-wrap gap-2" role="group" aria-label="Jenis talenta">
                    <button type="button" class="fchip h-9 px-3.5 text-sm" wire:click="setJenis('')" aria-pressed="{{ $jenis === '' ? 'true' : 'false' }}">Semua</button>
                    @foreach ($types as $type)
                        <button type="button" class="fchip h-9 px-3.5 text-sm" wire:click="setJenis('{{ $type->code->value }}')" aria-pressed="{{ $jenis === $type->code->value ? 'true' : 'false' }}">
                            {{ $type->code->label() }}<span class="sr-only">, {{ $type->bidang->label() }}</span>
                        </button>
                    @endforeach
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <x-sth.select wire:model.live="cabang" label="Cabang talenta" size="sm" :placeholder="$branches === [] ? 'Pilih jenis dulu' : 'Semua cabang'" :options="$branches" :disabled="$branches === []" />
                    <x-sth.select wire:model.live="kelas" label="Kelas" size="sm" placeholder="Semua kelas" :options="$classes" />
                    <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari" icon="search" placeholder="Nama atau NIS" />
                </div>
            </div>

            <div class="flex min-h-14 flex-wrap items-center gap-3 border-b border-line bg-subtle px-4 py-2.5" aria-live="polite">
                @if ($checked === [])
                    <span class="text-sm text-muted">{{ $shownText }} · Centang baris untuk pindah tahap sekaligus.</span>
                @else
                    <span class="text-sm font-bold">{{ count($checked) }} dipilih</span>
                    <x-sth.button size="sm" icon="check" wire:click="bulkAdvance" wire:loading.attr="disabled">Pindahkan ke {{ $bulkNext }}</x-sth.button>
                    <x-sth.button variant="danger" size="sm" wire:click="askBulkReject" aria-haspopup="dialog">Tidak lolos</x-sth.button>
                    <x-sth.button variant="quiet" size="sm" wire:click="cancelBulk">Batal pilih</x-sth.button>
                @endif
            </div>

            @if ($rows->isEmpty())
                <div class="p-4">
                    <x-sth.empty-state icon="search" title="Tidak ada pilihan talenta" :card="false">Ubah filter atau tahap.</x-sth.empty-state>
                </div>
            @else
                <x-sth.table caption="Daftar pilihan talenta" :card="false" fixed table-class="min-w-[720px]">
                    <x-slot:head>
                        <th scope="col" class="w-12">
                            <input type="checkbox" class="cek" aria-label="Pilih semua baris yang tampil" wire:click="toggleAll" @checked(count($checked) > 0 && count($checked) >= $rows->count())>
                        </th>
                        <th scope="col">Siswa</th>
                        <th scope="col" class="w-36 2xl:w-40">Talenta</th>
                        <th scope="col" class="w-20">Prestasi</th>
                        <th scope="col" class="w-32 2xl:w-40">Status</th>
                        <th scope="col" class="w-24"><span class="sr-only">Aksi</span></th>
                    </x-slot:head>
                    @foreach ($rows as $row)
                        <tr wire:key="t-{{ $row['id'] }}" @class(['bg-primary-wash' => $row['selected']])>
                            <td><input type="checkbox" class="cek" value="{{ $row['id'] }}" wire:model.live="checked" aria-label="Pilih {{ $row['name'] }}, {{ $row['type'] }} {{ $row['branch'] }}"></td>
                            <td>
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <x-sth.avatar :name="$row['name']" size="sm" class="hidden 2xl:inline-flex" />
                                    <div class="flex min-w-0 flex-col">
                                        <span class="ellip font-bold">{{ $row['name'] }}</span>
                                        <span class="text-xs text-muted">{{ $row['class'] }} · <span class="num">{{ $row['nis'] }}</span></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-col">
                                    <span class="font-bold">{{ $row['type'] }} <span class="font-medium">· {{ $row['branch'] }}</span></span>
                                    <span class="text-xs text-muted">{{ $row['bidang']?->label() }}{{ $row['hasEvidence'] ? ' · ada bukti' : '' }}</span>
                                </div>
                            </td>
                            <td class="num whitespace-nowrap text-sm">{{ $row['points'] !== null ? $row['points'].' · '.$row['prestasi'] : '–' }}</td>
                            <td>
                                <div class="flex flex-col items-start gap-1">
                                    <x-sth.chip :tone="$row['status']->tone()" size="sm" class="h-auto min-h-6 py-0.5 whitespace-normal">{{ $row['status']->label() }}</x-sth.chip>
                                    @if ($row['reviewRequested'])
                                        <x-sth.chip tone="orange" size="sm" class="h-auto min-h-6 py-0.5 whitespace-normal">Minta ditinjau ulang</x-sth.chip>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5">
                                    <x-sth.icon-button icon="eye" size="sm" :label="'Lihat '.$row['name']" wire:click="showPanel('{{ $row['id'] }}')" aria-pressed="{{ $row['selected'] ? 'true' : 'false' }}" />
                                    @if ($row['restorable'])
                                        <x-sth.icon-button icon="recycle" size="sm" :label="'Pulihkan '.$row['name'].', '.$row['type'].' '.$row['branch']" wire:click="askRestore('{{ $row['id'] }}')" aria-haspopup="dialog" />
                                    @else
                                        <x-sth.icon-button icon="check" size="sm" :label="$row['next'] ? 'Lolos ke '.$row['next']->label() : 'Tahap final'" wire:click="advanceRow('{{ $row['id'] }}')" :disabled="$row['next'] === null" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-sth.table>
                @if ($hasMore)
                    <div class="flex justify-center p-4">
                        <x-sth.button variant="quiet" size="sm" wire:click="showMore">Tampilkan lebih banyak</x-sth.button>
                    </div>
                @elseif ($capped)
                    <p class="m-0 p-4 text-center text-xs text-muted">Menampilkan {{ $rows->count() }} baris pertama. Persempit filter untuk melihat lainnya.</p>
                @endif
            @endif
        </x-sth.card>

        <x-sth.card as="aside" aria-label="Detail talenta" class="flex flex-col gap-4">
            @if ($panel === null)
                <p class="m-0 text-sm text-muted">Pilih baris untuk melihat detail.</p>
            @else
                <div class="flex items-start gap-3">
                    <x-sth.avatar :name="$panel['name']" size="lg" />
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="title-card">{{ $panel['name'] }}</span>
                        <span class="text-xs text-muted">{{ $panel['meta'] }}</span>
                    </div>
                    @if ($panel['points'])
                        <div class="flex flex-col items-end gap-0.5">
                            <x-sth.chip tone="gold" size="sm" class="num">{{ $panel['points'] }} poin</x-sth.chip>
                            <span class="text-xs text-muted">{{ $panel['prestasi'] }} prestasi di STH</span>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-2">
                    <h3 class="label m-0">Pilihan talenta · {{ count($panel['choices']) }}</h3>
                    <div class="flex flex-col gap-1.5">
                        @foreach ($panel['choices'] as $choice)
                            <button type="button" wire:click="showPanel('{{ $choice['id'] }}')" aria-pressed="{{ $choice['current'] ? 'true' : 'false' }}"
                                @class(['flex items-center justify-between gap-2 rounded-control border px-3 py-2 text-left', 'border-primary bg-primary-wash' => $choice['current'], 'border-line bg-surface' => ! $choice['current']])>
                                <span class="text-sm font-bold">{{ $choice['label'] }}</span>
                                <x-sth.chip :tone="$choice['status']->tone()" size="sm">{{ $choice['status']->label() }}</x-sth.chip>
                            </button>
                        @endforeach
                    </div>
                </div>

                @if ($panel['evidence'])
                    <div class="flex items-center gap-3 rounded-tile border border-line p-3">
                        <span class="inline-flex size-10 items-center justify-center rounded-tile bg-primary-tint text-primary"><x-sth.icon name="file" /></span>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="ellip text-sm font-bold">{{ $panel['evidence']['name'] }}</span>
                            <span class="text-xs text-muted">Bukti dukung · {{ $panel['evidence']['size'] }}</span>
                        </span>
                        @if ($panel['evidence']['url'])
                            <x-sth.button variant="quiet" size="sm" icon="eye" :href="$panel['evidence']['url']" aria-haspopup="dialog"
                                :data-sth-preview="$panel['evidence']['preview']" :data-sth-preview-type="$panel['evidence']['previewType']"
                                :data-sth-preview-name="$panel['evidence']['name']">Buka</x-sth.button>
                        @endif
                    </div>
                @else
                    <p class="m-0 rounded-tile border border-dashed border-line-dashed p-3 text-xs text-muted">Tidak ada bukti dukung · Opsional</p>
                @endif

                {{-- The student types these: one long word (a link, a handle) wraps inside the card (QA6, Q2-29). --}}
                <dl class="m-0 grid grid-cols-[110px_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm">
                    <dt class="text-muted">Minat</dt><dd class="m-0 [overflow-wrap:anywhere]">{{ $panel['minat'] ?? '–' }}</dd>
                    <dt class="text-muted">Organisasi</dt><dd class="m-0 [overflow-wrap:anywhere]">{{ $panel['organisasi'] ?? '–' }}</dd>
                    <dt class="text-muted">Prestasi SMP</dt><dd class="m-0 [overflow-wrap:anywhere]">{{ $panel['smp'] ?? '–' }}</dd>
                </dl>

                @if ($panel['final'])
                    <x-sth.alert :tone="$panel['restorable'] ? 'error' : 'success'" :title="$panel['status']->label()">
                        {{ $panel['restorable'] ? $panel['restoreHint'] : 'Pembina: '.($panel['mentorName'] ?? 'belum ditentukan') }}
                    </x-sth.alert>
                @endif

                @if ($panel['review'])
                    <x-sth.alert tone="warning" title="Siswa minta ditinjau ulang">
                        <span class="block text-xs text-muted">{{ $panel['review']['at'] }}</span>
                        <span class="block whitespace-pre-line break-words">{{ $panel['review']['message'] ?? 'Tanpa pesan.' }}</span>
                    </x-sth.alert>
                @endif

                @if ($panel['studentNote'])
                    <div class="flex flex-col gap-1 rounded-tile bg-subtle p-3 text-sm">
                        <span class="label">Catatan terakhir untuk siswa</span>
                        <span class="whitespace-pre-line break-words">{{ $panel['studentNote'] }}</span>
                    </div>
                @endif

                <div class="flex flex-col gap-3">
                    <x-sth.select wire:model="mentorId" label="Guru pembina" placeholder="Belum ditentukan" :options="$mentors" />
                    @unless ($panel['final'])
                        <x-sth.input type="date" wire:model="assessmentOn" label="Jadwal asesmen" />
                    @endunless
                    <x-sth.textarea wire:model="panelNote" label="Catatan untuk guru pembina" optional rows="3" maxlength="1000"
                        hint="Hanya dibaca guru pembina dan Kesiswaan. Siswa tidak melihatnya." />
                    @unless ($panel['final'])
                        {{-- "Simpan" keeps this text as a draft on the row; only a decision sends it (Q2-14). --}}
                        <x-sth.textarea wire:model="panelStudentNote" label="Catatan untuk siswa" optional rows="3" maxlength="1000"
                            :hint="($panel['hasDraft'] ? 'Draf tersimpan, belum terkirim. ' : '').'Dibaca siswa di notifikasi dan Profil talenta. Terkirim saat Lolos atau Tidak lolos ditekan. Simpan hanya menyimpan draf.'" />
                    @endunless
                    @error('panel')<x-sth.alert tone="error">{{ $message }}</x-sth.alert>@enderror
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($panel['next'])
                        <x-sth.button icon="check" wire:click="advance" wire:loading.attr="disabled">Lolos ke {{ $panel['next']->label() }}</x-sth.button>
                    @endif
                    @unless ($panel['final'])
                        <x-sth.button variant="danger" wire:click="askReject" wire:loading.attr="disabled" aria-haspopup="dialog">Tidak lolos</x-sth.button>
                    @endunless
                    @if ($panel['restorable'])
                        <x-sth.button icon="recycle" wire:click="askRestore('{{ $panel['id'] }}')" wire:loading.attr="disabled" aria-haspopup="dialog">Pulihkan</x-sth.button>
                    @endif
                    <x-sth.button variant="quiet" wire:click="savePanel" wire:loading.attr="disabled">Simpan</x-sth.button>
                </div>
                <p class="m-0 text-xs text-muted">{{ $panelDone ?? 'Siswa menerima notifikasi setiap perubahan tahap.' }}</p>
            @endif
        </x-sth.card>
    </div>

    {{-- "Tidak lolos" (panel row or checked rows): the student is told at once, so it asks first (Q2-15). --}}
    <x-sth.modal :id="\App\Livewire\Kesiswaan\Talenta::REJECT_DIALOG" title="Tandai tidak lolos?" description="Siswa langsung diberi tahu." size="sm">
        <form id="tidak-lolos-talenta-form" wire:submit="reject" class="flex flex-col gap-4" novalidate>
            @if ($rejecting && $rejecting['bulk'])
                <p class="m-0 text-sm">
                    <span class="font-bold"><span class="num">{{ $rejecting['count'] }}</span> pilihan talenta</span> ditandai tidak lolos. Tiap pilihan bisa dipulihkan lagi nanti.
                </p>
                @if ($rejecting['skipped'] > 0)
                    <p class="m-0 text-xs text-muted">
                        <span class="num">{{ $rejecting['skipped'] }}</span> pilihan yang dicentang sudah final (Peserta pembinaan atau Tidak lolos) dan dilewati.
                    </p>
                @endif
                <x-sth.textarea wire:model="bulkNote" label="Catatan untuk siswa" optional rows="3" maxlength="1000"
                    hint="Catatan yang sama terkirim ke semua siswa yang dipilih." />
                @if ($rejecting['drafts'] > 0)
                    <p class="m-0 text-xs text-muted">
                        <span class="num">{{ $rejecting['drafts'] }}</span> pilihan punya catatan untuk siswa yang tersimpan. Kalau kolom di atas kosong, catatan itu yang terkirim.
                    </p>
                @endif
            @elseif ($rejecting)
                <p class="m-0 text-sm">
                    <span class="font-bold">{{ $rejecting['name'] }}</span> · {{ $rejecting['talent'] }} tidak lolos dari tahap {{ $rejecting['from'] }}. Bisa dipulihkan lagi nanti.
                </p>
                @if ($rejecting['note'])
                    <div class="flex flex-col gap-1 rounded-tile bg-subtle p-3 text-sm">
                        <span class="label">Catatan untuk siswa</span>
                        <span class="whitespace-pre-line break-words">{{ $rejecting['note'] }}</span>
                    </div>
                @else
                    <p class="m-0 text-sm text-muted">Tanpa catatan untuk siswa.</p>
                @endif
            @else
                <p class="m-0 text-md text-muted">Tidak ada yang dipilih. Tutup lalu coba lagi.</p>
            @endif
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($rejecting)
                <x-sth.button type="submit" form="tidak-lolos-talenta-form" variant="danger" wire:loading.attr="disabled" wire:target="reject">Tidak lolos</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>

    {{-- "Pulihkan": a tidak-lolos choice goes back into the stage Kesiswaan ticks; the earlier stage is preselected (M3-A). --}}
    <x-sth.modal :id="\App\Livewire\Kesiswaan\Talenta::RESTORE_DIALOG" title="Pulihkan pilihan talenta?" description="Pilih tahap tujuannya. Siswa diberi tahu." size="sm">
        <form id="pulihkan-talenta-form" wire:submit="restore" class="flex flex-col gap-4" novalidate>
            @if ($restoring)
                <p class="m-0 text-sm">
                    <span class="font-bold">{{ $restoring['name'] }}</span> · {{ $restoring['talent'] }}
                    {{ $restoring['from'] ? 'tidak lolos dari tahap '.$restoring['from'].'.' : 'tidak lolos.' }}
                    @if ($restoring['requested'])Permintaan tinjau ulang siswa ikut ditutup.@endif
                </p>
                <x-sth.radio-pick wire:model="restoreTo" label="Kembalikan ke tahap" :value="$restoreTo" :columns="2" size="sm" :options="$restoring['options']"
                    :hint="$restoring['from'] ? null : 'Tahap sebelumnya tidak tercatat, jadi Terdaftar yang dipilih.'" />
                {{-- D-109: Peserta pembinaan always has a guru pembina; the current one is preselected. --}}
                <x-sth.select wire:model="restoreMentor" label="Guru pembina" placeholder="Belum ditentukan" :options="$mentors"
                    hint="Wajib kalau dipulihkan ke Peserta pembinaan." />
            @endif
            <x-sth.textarea wire:model="restoreNote" label="Catatan untuk siswa" optional rows="3" maxlength="1000"
                hint="Dibaca siswa di notifikasi dan Profil talenta." />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="pulihkan-talenta-form" icon="recycle" wire:loading.attr="disabled" wire:target="restore">Pulihkan</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    {{-- "Download data (Excel)": tick the statuses that go into the file; the open tab is preselected (M3-A). --}}
    <x-sth.modal :id="\App\Livewire\Kesiswaan\Talenta::EXPORT_DIALOG" title="Download data talenta" description="Pilih status yang masuk ke file Excel." size="sm">
        <form id="download-talenta-form" wire:submit="download" class="flex flex-col gap-4" novalidate>
            <x-sth.radio-pick wire:model="exportStatuses" multiple label="Status" :value="$exportStatuses" :columns="2" size="sm" :options="$exportOptions" />
            <div class="flex flex-wrap gap-2">
                <x-sth.button variant="quiet" size="sm" wire:click="selectAllExport" wire:loading.attr="disabled">Pilih semua status</x-sth.button>
            </div>
            <p class="m-0 text-xs text-muted">{{ $exportScope }}</p>
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="download-talenta-form" icon="download" wire:loading.attr="disabled" wire:target="download">Download</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>
</div>
