{{--
    Import data siswa (board Admin-Import-Siswa; kesiswaan.import + admin.import). The initial password pattern is never shown.
    M1-C: the file lists every student with the current class; registered students only move class (no account change).
--}}
<div class="flex flex-col gap-[22px]">
    <ol class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-3" aria-label="Langkah import">
        @foreach ([1 => ['Download template', 'Kolom sesuai format sekolah'], 2 => ['Upload file', $preview ? $preview['fileName'] : '.xlsx atau .csv, maks 2 MB'], 3 => ['Periksa & impor', $imported ? 'Selesai' : ($preview ? $total.' baris terbaca' : 'Belum ada file')]] as $n => [$label, $sub])
            <li @class(['card flex items-center gap-3 p-3.5', 'border-primary' => $step === $n])>
                <span @class(['inline-flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                    'bg-primary text-white' => $step === $n, 'bg-green-bg text-green' => $step > $n, 'bg-gray-bg text-gray' => $step < $n])>
                    @if ($step > $n)<x-sth.icon name="check" :size="18" label="Selesai" />@else{{ $n }}@endif
                </span>
                <span class="flex min-w-0 flex-col">
                    <span class="font-bold" @if ($step === $n) aria-current="step" @endif>{{ $label }}</span>
                    <span class="ellip text-xs text-muted">{{ $sub }}</span>
                </span>
            </li>
        @endforeach
    </ol>

    <div class="grid grid-cols-1 items-start gap-[22px] lg:grid-cols-[minmax(300px,380px)_1fr]">
        <div class="flex flex-col gap-4">
            <x-sth.card as="section" aria-labelledby="h-template" class="flex flex-col gap-3">
                <h2 id="h-template" class="title-card m-0">1 · Download template</h2>
                <ul class="m-0 flex list-none flex-wrap gap-1.5 p-0">
                    @foreach (['Nama lengkap', 'NIS', 'NISN', 'Tanggal lahir', 'Kelas', 'No HP (opsional)'] as $column)
                        <li><x-sth.chip size="sm">{{ $column }}</x-sth.chip></li>
                    @endforeach
                </ul>
                <p class="m-0 text-xs text-muted">Tanggal lahir DD-MM-YYYY. Format kolom NIS dan NISN sebagai teks.</p>
                <p class="m-0 text-xs text-muted">Isi semua siswa dari semua angkatan dengan kelasnya sekarang, lalu upload lagi setiap tahun ajaran baru. Siswa yang sudah terdaftar hanya pindah ke kelas di file. Akun dan kata sandinya tidak berubah.</p>
                <x-sth.button variant="soft" icon="download" wire:click="downloadTemplate">Download template Excel</x-sth.button>
            </x-sth.card>

            <x-sth.card as="section" aria-labelledby="h-unggah" class="flex flex-col gap-3">
                <h2 id="h-unggah" class="title-card m-0">2 · Upload file</h2>
                <x-sth.select wire:model.live="schoolYear" label="Tahun ajaran data" :options="$yearOptions" hint="Dipakai untuk menghitung kelas XII dan tanggal arsip." />
                <x-sth.file-drop wire:model="file" label="File data siswa" accept=".xlsx,.csv" icon="upload"
                    :title="$preview ? $preview['fileName'] : 'Pilih file atau seret ke sini'" hint=".xlsx atau .csv · maks. 2 MB · pakai template dari langkah 1" />
                <p wire:loading wire:target="file" class="m-0 text-xs text-muted">Membaca file…</p>
                @error('school_year')<x-sth.alert tone="error">{{ $message }}</x-sth.alert>@enderror
            </x-sth.card>
        </div>

        <x-sth.card as="section" aria-labelledby="h-periksa" class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="h-periksa" class="title-card m-0">3 · Periksa & impor</h2>
                @if ($preview)
                    <span class="text-xs text-muted">{{ $preview['fileName'] }} · Tahun ajaran {{ $preview['schoolYear'] }}</span>
                @endif
            </div>

            {{-- B2 (#19): queued import of many rows; polls while it runs. --}}
            @if ($job)
                <div class="flex flex-col gap-2" @if ($job->isActive()) wire:poll.2s @endif>
                    @if ($job->isActive())
                        <x-sth.alert :title="$job->status->label()">
                            {{ $job->total_rows > 0 ? $job->processed_rows.' dari '.$job->total_rows.' baris diproses' : 'Menunggu giliran' }} · {{ $jobResult }}.
                            Halaman ini diperbarui otomatis dan boleh ditinggalkan.
                        </x-sth.alert>
                        <span class="block h-2 w-full overflow-hidden rounded-full bg-subtle" aria-hidden="true">
                            <span class="block h-full rounded-full bg-primary w-pct-{{ $job->progressPercent() }}"></span>
                        </span>
                    @elseif ($job->status === \App\Enums\StudentImportStatus::Done)
                        <x-sth.alert tone="success" :title="$jobResult">
                            {{ $job->invalid_rows > 0 ? $job->invalid_rows.' baris ditolak dan tidak diimpor. Baris lain sudah sesuai.' : 'Semua baris sudah sesuai dengan file.' }}
                        </x-sth.alert>
                    @else
                        <x-sth.alert tone="error" title="Impor gagal">{{ $job->error }} {{ $job->created_count + $job->updated_count > 0 ? 'Sebelum berhenti: '.$jobResult.'.' : '' }}</x-sth.alert>
                    @endif
                </div>
            @endif

            @if ($preview === null)
                @unless ($job)
                    <x-sth.empty-state icon="file" title="Belum ada file untuk diperiksa" :card="false">Upload file Excel di langkah 2.</x-sth.empty-state>
                @endunless
            @else
                @if ($imported)
                    <x-sth.alert tone="success" :title="$importedResult">
                        @if ($imported['invalid'] > 0)
                            {{ $imported['invalid'] }} baris ditolak dan belum diimpor. Perbaiki lalu upload ulang.
                        @elseif ($imported['created'] > 0)
                            Siswa baru bisa masuk dengan NIS atau NISN dan kata sandi awal dari sekolah.
                        @else
                            Data kelas sudah sesuai dengan file.
                        @endif
                    </x-sth.alert>
                @else
                    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                        <x-sth.stat-tile icon="check-circle" tone="green" label="Siswa baru" :value="(string) $summary['new']" note="akun dibuat" />
                        <x-sth.stat-tile icon="users" tone="blue" label="Pindah kelas" :value="(string) $summary['moved']" note="kelas diperbarui" />
                        <x-sth.stat-tile icon="check" tone="gray" label="Tidak berubah" :value="(string) ($summary['unchanged'] + $summary['skipped'])" note="sudah di kelas itu" />
                        <x-sth.stat-tile icon="alert" tone="red" label="Ditolak" :value="(string) $summary['rejected']" note="perlu diperbaiki" zero="hide" />
                    </div>
                @endif
                @if ($archiveCorrections > 0 && ! $imported)
                    <x-sth.alert tone="warning" :title="'Tanggal arsip '.$archiveCorrections.' siswa lama akan berubah'">
                        Ini wajar untuk siswa yang tinggal kelas atau dulu salah kelas. Kalau jumlahnya banyak, periksa pilihan tahun ajaran di langkah 2 sebelum mengimpor.
                    </x-sth.alert>
                @endif
                @if ($summary['skipped'] > 0)
                    <p class="m-0 text-xs text-muted">{{ $summary['skipped'] }} baris adalah akun alumni (sudah diarsipkan) dan dilewati. Kalau siswanya masih bersekolah, minta Admin memulihkan akunnya dulu.</p>
                @endif
                @if ($sanitizedCount > 0)
                    <p class="m-0 text-xs text-muted">{{ $sanitizedCount }} baris berisi sel yang diawali = + - @. Karakter itu dibuang (tanda "Sel dibersihkan"); periksa datanya sebelum mengimpor.</p>
                @endif

                <div class="seg self-start" role="group" aria-label="Tampilkan baris">
                    <button type="button" class="seg-btn" wire:click="$set('filter', 'all')" aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}">Semua baris</button>
                    <button type="button" class="seg-btn" wire:click="$set('filter', 'bad')" aria-pressed="{{ $filter === 'bad' ? 'true' : 'false' }}">Ditolak · {{ $invalidCount }}</button>
                    <button type="button" class="seg-btn" wire:click="$set('filter', 'notes')" aria-pressed="{{ $filter === 'notes' ? 'true' : 'false' }}">Ada catatan · {{ $notedCount }}</button>
                </div>

                @if ($list === [])
                    <p class="m-0 text-sm text-muted">{{ $filter === 'notes' ? 'Tidak ada baris dengan catatan.' : 'Tidak ada baris yang ditolak.' }}</p>
                @else
                    <x-sth.table caption="Preview {{ count($list) }} dari {{ $listTotal }} baris" caption-visible :card="false">
                        <x-slot:head>
                            <th scope="col" class="w-14">Baris</th>
                            <th scope="col">Nama</th>
                            <th scope="col">NIS</th>
                            <th scope="col">Kelas</th>
                            <th scope="col">Angkatan</th>
                            <th scope="col">Kelas XII mulai</th>
                            <th scope="col">Arsip otomatis</th>
                            <th scope="col">Status</th>
                        </x-slot:head>
                        @foreach ($list as $row)
                            <tr wire:key="row-{{ $row['row'] }}" @class(['bg-red-bg' => $row['errors'] !== []])>
                                <td class="num">{{ $row['row'] }}</td>
                                <td class="font-bold">{{ $row['name'] ?? '–' }}</td>
                                <td class="num">{{ $row['nis'] ?? '–' }}</td>
                                <td>{{ $row['kelas'] ?? '–' }}</td>
                                <td class="num">{{ $row['angkatan'] ?? '–' }}</td>
                                <td>{{ $row['grade12'] }}</td>
                                <td>{{ $row['archive'] }}</td>
                                <td>
                                    @if ($row['errors'] === [])
                                        <x-sth.chip :tone="$row['tone']" size="sm">{{ $row['status'] }}</x-sth.chip>
                                    @else
                                        <span class="text-xs font-semibold text-red">{{ implode(' ', $row['errors']) }}</span>
                                    @endif
                                    @if ($row['sanitized'] ?? false)
                                        <x-sth.chip tone="orange" size="sm">Sel dibersihkan</x-sth.chip>
                                    @endif
                                    @foreach ($row['notes'] as $note)
                                        <span class="block text-xs text-orange">{{ $note }}</span>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </x-sth.table>
                @endif

                <div class="flex flex-col gap-1 text-xs text-muted">
                    <span>Arsip otomatis = 18 bulan setelah awal kelas XII. Akun yang diarsipkan tidak bisa masuk.</span>
                    <span>Kata sandi awal hanya dibuat untuk siswa baru, otomatis sesuai pola sekolah. Kata sandi siswa lama tidak berubah.</span>
                </div>

                @error('file')<x-sth.alert tone="error">{{ $message }}</x-sth.alert>@enderror

                <div class="flex flex-wrap gap-2">
                    @if ($imported || $job)
                        <x-sth.button variant="soft" icon="upload" wire:click="startOver" :disabled="$job?->isActive() ?? false">Impor file lain</x-sth.button>
                    @else
                        <x-sth.button icon="check" wire:click="import" wire:loading.attr="disabled" :disabled="$changeCount === 0"
                            wire:confirm="{{ $confirm }}">Impor {{ $changeCount }} siswa</x-sth.button>
                        <x-sth.button variant="quiet" wire:click="startOver">Ganti file</x-sth.button>
                    @endif
                    @if ($invalidCount + $notedCount > 0)
                        <x-sth.button variant="quiet" icon="download" wire:click="downloadInvalid">Download baris yang perlu dicek</x-sth.button>
                    @endif
                </div>
            @endif
        </x-sth.card>
    </div>
</div>
