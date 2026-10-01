{{-- Import data siswa (board Admin-Import-Siswa; kesiswaan.import + admin.import). The initial password pattern is never shown. --}}
<div class="flex flex-col gap-[22px]">
    <ol class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-3" aria-label="Langkah import">
        @foreach ([1 => ['Unduh template', 'Kolom sesuai format sekolah'], 2 => ['Unggah file', $preview ? $preview['fileName'] : '.xlsx atau .csv, maks 2 MB'], 3 => ['Periksa & impor', $imported ? 'Selesai' : ($preview ? $total.' baris terbaca' : 'Belum ada file')]] as $n => [$label, $sub])
            <li @class(['card flex items-center gap-3 p-3.5', 'border-primary' => $step === $n])>
                <span @class(['inline-flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-extrabold',
                    'bg-primary text-white' => $step === $n, 'bg-green-bg text-green' => $step > $n, 'bg-gray-bg text-gray' => $step < $n])>
                    @if ($step > $n)<x-sth.icon name="check" :size="18" label="Selesai" />@else{{ $n }}@endif
                </span>
                <span class="flex min-w-0 flex-col">
                    <span class="font-bold" @if ($step === $n) aria-current="step" @endif>{{ $label }}</span>
                    <span class="ellip text-[12.5px] text-muted">{{ $sub }}</span>
                </span>
            </li>
        @endforeach
    </ol>

    <div class="grid grid-cols-1 items-start gap-[22px] lg:grid-cols-[minmax(300px,380px)_1fr]">
        <div class="flex flex-col gap-4">
            <x-sth.card as="section" aria-labelledby="h-template" class="flex flex-col gap-3">
                <h2 id="h-template" class="title-card m-0">1 · Unduh template</h2>
                <ul class="m-0 flex list-none flex-wrap gap-1.5 p-0">
                    @foreach (['Nama lengkap', 'NIS', 'NISN', 'Tanggal lahir', 'Kelas saat pendaftaran', 'No HP (opsional)'] as $column)
                        <li><x-sth.chip size="sm">{{ $column }}</x-sth.chip></li>
                    @endforeach
                </ul>
                <p class="m-0 text-[13px] text-muted">Tanggal lahir DD-MM-YYYY. Format kolom NIS dan NISN sebagai teks.</p>
                <x-sth.button variant="soft" icon="download" wire:click="downloadTemplate">Unduh template Excel</x-sth.button>
            </x-sth.card>

            <x-sth.card as="section" aria-labelledby="h-unggah" class="flex flex-col gap-3">
                <h2 id="h-unggah" class="title-card m-0">2 · Unggah file</h2>
                <x-sth.select wire:model.live="schoolYear" label="Tahun ajaran data" :options="$yearOptions" hint="Dipakai untuk menghitung kelas XII dan tanggal arsip." />
                <x-sth.file-drop wire:model="file" label="File data siswa" accept=".xlsx,.csv" icon="upload"
                    :title="$preview ? $preview['fileName'] : 'Pilih file atau seret ke sini'" hint=".xlsx atau .csv · maks. 2 MB · pakai template dari langkah 1" />
                <p wire:loading wire:target="file" class="m-0 text-[13px] text-muted">Membaca file…</p>
                @error('school_year')<x-sth.alert tone="error">{{ $message }}</x-sth.alert>@enderror
            </x-sth.card>
        </div>

        <x-sth.card as="section" aria-labelledby="h-periksa" class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="h-periksa" class="title-card m-0">3 · Periksa & impor</h2>
                @if ($preview)
                    <span class="text-[13px] text-muted">{{ $preview['fileName'] }} · Tahun ajaran {{ $preview['schoolYear'] }}</span>
                @endif
            </div>

            {{-- B2 (#19): queued import of many rows; polls while it runs. --}}
            @if ($job)
                <div class="flex flex-col gap-2" @if ($job->isActive()) wire:poll.2s @endif>
                    @if ($job->isActive())
                        <x-sth.alert :title="$job->status->label()">
                            {{ $job->total_rows > 0 ? $job->processed_rows.' dari '.$job->total_rows.' baris diproses' : 'Menunggu giliran' }} · {{ $job->created_count }} akun dibuat.
                            Halaman ini diperbarui otomatis dan boleh ditinggalkan.
                        </x-sth.alert>
                        <span class="block h-2 w-full overflow-hidden rounded-full bg-subtle" aria-hidden="true">
                            <span class="block h-full rounded-full bg-primary w-pct-{{ $job->progressPercent() }}"></span>
                        </span>
                    @elseif ($job->status === \App\Enums\StudentImportStatus::Done)
                        <x-sth.alert tone="success" :title="$job->created_count.' akun siswa dibuat'">
                            {{ $job->total_rows - $job->created_count > 0 ? ($job->total_rows - $job->created_count).' baris tidak diimpor (bermasalah atau sudah terdaftar).' : 'Semua baris diimpor.' }}
                        </x-sth.alert>
                    @else
                        <x-sth.alert tone="error" title="Impor gagal">{{ $job->error }} {{ $job->created_count > 0 ? $job->created_count.' akun sudah dibuat.' : '' }}</x-sth.alert>
                    @endif
                </div>
            @endif

            @if ($preview === null)
                @unless ($job)
                    <x-sth.empty-state icon="file" title="Belum ada file untuk diperiksa" :card="false">Unggah file Excel di langkah 2.</x-sth.empty-state>
                @endunless
            @else
                @if ($imported)
                    <x-sth.alert tone="success" :title="$imported['created'].' akun siswa dibuat'">
                        @if ($imported['invalid'] > 0)
                            {{ $imported['invalid'] }} baris bermasalah belum diimpor. Perbaiki lalu unggah ulang.
                        @else
                            Siswa bisa masuk dengan NIS atau NISN dan kata sandi awal dari sekolah.
                        @endif
                    </x-sth.alert>
                @else
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <x-sth.stat-tile icon="check-circle" tone="green" label="Siap diimpor" :value="(string) $validCount" />
                        <x-sth.stat-tile icon="alert" tone="red" label="Perlu diperbaiki" :value="(string) $invalidCount" />
                        <x-sth.stat-tile icon="users" tone="orange" label="Duplikat dalam file" :value="(string) $duplicateCount" />
                    </div>
                @endif
                @if ($sanitizedCount > 0)
                    <p class="m-0 text-[13px] text-muted">{{ $sanitizedCount }} baris berisi sel yang diawali = + - @. Karakter itu dibuang (tanda "Sel dibersihkan"); periksa datanya sebelum mengimpor.</p>
                @endif

                <div class="seg self-start" role="group" aria-label="Tampilkan baris">
                    <button type="button" class="seg-btn" wire:click="$set('filter', 'all')" aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}">Semua baris</button>
                    <button type="button" class="seg-btn" wire:click="$set('filter', 'bad')" aria-pressed="{{ $filter === 'bad' ? 'true' : 'false' }}">Perlu diperbaiki · {{ $invalidCount }}</button>
                </div>

                @if ($list === [])
                    <p class="m-0 text-[13.5px] text-muted">Tidak ada baris bermasalah.</p>
                @else
                    <x-sth.table caption="Pratinjau {{ count($list) }} dari {{ $listTotal }} baris" caption-visible :card="false">
                        <x-slot:head>
                            <th scope="col" class="w-14">Baris</th>
                            <th scope="col">Nama</th>
                            <th scope="col">NIS</th>
                            <th scope="col">Kelas awal</th>
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
                                <td>{{ $row['classroom'] ?? '–' }}</td>
                                <td class="num">{{ $row['angkatan'] ?? '–' }}</td>
                                <td>{{ $row['grade12'] }}</td>
                                <td>{{ $row['archive'] }}</td>
                                <td>
                                    @if ($row['errors'] === [])
                                        <x-sth.chip tone="green" size="sm">Siap</x-sth.chip>
                                    @else
                                        <span class="text-[13px] font-semibold text-red">{{ implode(' ', $row['errors']) }}</span>
                                    @endif
                                    @if ($row['sanitized'] ?? false)
                                        <x-sth.chip tone="orange" size="sm">Sel dibersihkan</x-sth.chip>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-sth.table>
                @endif

                <div class="flex flex-col gap-1 text-[13px] text-muted">
                    <span>Arsip otomatis = 18 bulan setelah awal kelas XII. Akun yang diarsipkan tidak bisa masuk.</span>
                    <span>Kata sandi awal dibuat otomatis sesuai pola sekolah.</span>
                </div>

                @error('file')<x-sth.alert tone="error">{{ $message }}</x-sth.alert>@enderror

                <div class="flex flex-wrap gap-2">
                    @if ($imported || $job)
                        <x-sth.button variant="soft" icon="upload" wire:click="startOver" :disabled="$job?->isActive() ?? false">Impor file lain</x-sth.button>
                    @else
                        <x-sth.button icon="check" wire:click="import" wire:loading.attr="disabled" :disabled="$validCount === 0"
                            wire:confirm="Buat {{ $validCount }} akun siswa sekarang?">Impor {{ $validCount }} siswa</x-sth.button>
                        <x-sth.button variant="quiet" wire:click="startOver">Ganti file</x-sth.button>
                    @endif
                    @if ($invalidCount > 0)
                        <x-sth.button variant="quiet" icon="download" wire:click="downloadInvalid">Unduh baris bermasalah</x-sth.button>
                    @endif
                </div>
            @endif
        </x-sth.card>
    </div>
</div>
