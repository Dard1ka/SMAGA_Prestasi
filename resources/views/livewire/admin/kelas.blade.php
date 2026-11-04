{{-- Admin · Kelas (App\Livewire\Admin\Kelas). Groups, counts, names and every dialog sentence come from the component; this view only formats. --}}
<div class="flex flex-col gap-[22px]">
    <x-sth.alert>Import siswa hanya memasukkan siswa ke kelas yang ada di daftar ini. Kelas baru bisa langsung dipakai di kolom "Kelas" file import.</x-sth.alert>

    <x-sth.card as="section" aria-labelledby="h-kelas-tambah" class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <h2 id="h-kelas-tambah" class="title-card">Tambah kelas</h2>
            <p class="muted m-0 text-sm">Pilih tingkat, lalu ketik bagian setelah tanda hubung. Contoh: tingkat XI dan nama IPA 1 menjadi XI-IPA 1.</p>
        </div>
        <form wire:submit="add" class="flex flex-col gap-4" novalidate>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[10rem_minmax(0,20rem)]">
                <x-sth.select wire:model="level" label="Tingkat" :options="$levelOptions" />
                <x-sth.input wire:model="suffix" label="Nama kelas" maxlength="12" autocomplete="off"
                             hint="Huruf, angka, dan spasi, maksimal 12 karakter." />
            </div>
            <div>
                <x-sth.button type="submit" icon="plus" wire:loading.attr="disabled" wire:target="add">Tambah kelas</x-sth.button>
            </div>
        </form>
    </x-sth.card>

    @forelse ($groups as $group)
        <section aria-labelledby="h-kelas-{{ $group['level'] }}" class="flex flex-col gap-3" wire:key="tingkat-{{ $group['level'] }}">
            <x-sth.page-header :id="'h-kelas-'.$group['level']" :title="$group['label']"
                               :subtitle="count($group['rows']).' kelas · '.$group['active'].' siswa aktif'" />
            <x-sth.table :caption="$group['label'].', '.count($group['rows']).' kelas'">
                <x-slot:head>
                    <th scope="col">Kelas</th>
                    <th scope="col" class="text-right">Siswa aktif</th>
                    <th scope="col" class="text-right">Semua siswa</th>
                    <th scope="col" class="relative"><span class="sr-only">Aksi</span></th>
                </x-slot:head>
                @foreach ($group['rows'] as $row)
                    <tr wire:key="kelas-{{ $row['id'] }}">
                        <td>
                            {{-- The lead (2026-11-04): a click on the class shows its students, like "Lihat siswa". --}}
                            <button type="button" class="ttl-link cursor-pointer text-left font-semibold [overflow-wrap:anywhere]" aria-haspopup="dialog"
                                    wire:click="showStudents('{{ $row['id'] }}')">{{ $row['name'] }}<span class="sr-only">, lihat siswa</span></button>
                            @unless ($row['enabled'])
                                <x-sth.chip tone="gray" size="sm" class="ml-1">Tidak aktif</x-sth.chip>
                            @endunless
                        </td>
                        <td class="num text-right">{{ $row['active'] }}</td>
                        <td class="num text-right">{{ $row['total'] }}</td>
                        <td class="text-right">
                            <span class="inline-flex flex-wrap justify-end gap-2">
                                <x-sth.button variant="quiet" size="sm" icon="users" aria-haspopup="dialog" wire:click="showStudents('{{ $row['id'] }}')">
                                    Lihat siswa<span class="sr-only"> kelas {{ $row['name'] }}</span>
                                </x-sth.button>
                                <x-sth.button variant="quiet" size="sm" icon="edit" wire:click="askRename('{{ $row['id'] }}')">
                                    Ganti nama<span class="sr-only"> kelas {{ $row['name'] }}</span>
                                </x-sth.button>
                                <x-sth.button variant="quiet" size="sm" icon="trash" aria-haspopup="dialog" wire:click="askDelete('{{ $row['id'] }}')"
                                              wire:loading.attr="disabled" wire:target="askDelete">
                                    Hapus<span class="sr-only"> kelas {{ $row['name'] }}</span>
                                </x-sth.button>
                            </span>
                        </td>
                    </tr>
                @endforeach
            </x-sth.table>
        </section>
    @empty
        <x-sth.empty-state icon="folder" title="Belum ada kelas">
            Tambahkan kelas dulu sebelum import siswa.
        </x-sth.empty-state>
    @endforelse

    @if ($groups !== [])
        <p class="muted m-0 text-sm">Lihat siswa menampilkan siswa satu kelas; status akunnya bisa diubah dari sana, satu per satu atau beberapa sekaligus. Hapus memindahkan kelas beserta semua siswanya ke Sampah; selama 7 hari masih bisa dipulihkan. Tingkat kelas tidak bisa diganti karena tanggal arsip siswanya mengikuti tingkat.</p>
    @endif

    {{-- Sampah (the lead's decision of 5 Nov 2026): classes with their students and students deleted alone, 7 days. --}}
    <section aria-labelledby="h-kelas-sampah" class="flex flex-col gap-3">
        <x-sth.page-header id="h-kelas-sampah" title="Sampah"
                           subtitle="Kelas dan siswa yang dihapus. Selama 7 hari masih bisa dipulihkan, setelah itu terhapus permanen." />
        @if ($trash['rows'] === [])
            <x-sth.empty-state icon="trash" title="Sampah kosong">Kelas dan siswa yang dihapus tampil di sini selama 7 hari.</x-sth.empty-state>
        @else
            <x-sth.table :caption="'Sampah, '.$trash['total'].' data'">
                <x-slot:head>
                    <th scope="col" class="min-w-44">Kelas atau siswa</th>
                    <th scope="col" class="min-w-52">Dihapus</th>
                    <th scope="col" class="relative"><span class="sr-only">Aksi</span></th>
                </x-slot:head>
                @foreach ($trash['rows'] as $entry)
                    <tr wire:key="sampah-{{ $entry['id'] }}">
                        <td>
                            <div class="flex min-w-0 flex-col [overflow-wrap:anywhere]">
                                <span class="font-semibold">{{ $entry['title'] }}</span>
                                <span class="text-xs text-muted">{{ $entry['meta'] }}</span>
                            </div>
                        </td>
                        <td>
                            <div class="flex min-w-0 flex-col [overflow-wrap:anywhere]">
                                <span class="text-sm">{{ $entry['deleted'] }}</span>
                                <span class="text-xs text-muted">Alasan: {{ $entry['reason'] }}</span>
                                <span class="text-xs font-semibold">{{ $entry['purge'] }}</span>
                            </div>
                        </td>
                        <td class="text-right">
                            @if ($entry['restorable'])
                                <x-sth.button variant="quiet" size="sm" icon="recycle" aria-haspopup="dialog" wire:click="askRestore('{{ $entry['id'] }}')"
                                              wire:loading.attr="disabled" wire:target="askRestore">
                                    Pulihkan<span class="sr-only"> {{ $entry['title'] }}</span>
                                </x-sth.button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-sth.table>
            @if ($trash['capped'])
                <p class="m-0 text-sm text-muted">Menampilkan {{ count($trash['rows']) }} data terbaru dari {{ $trash['total'] }}.</p>
            @elseif ($trash['hasMore'])
                <div class="flex justify-center">
                    <x-sth.button variant="quiet" size="sm" icon="chevron-down" wire:click="showMoreTrash" wire:loading.attr="disabled" wire:target="showMoreTrash">Tampilkan lebih banyak</x-sth.button>
                </div>
            @endif
        @endif
    </section>

    {{-- Dialogs ----------------------------------------------------------------------------------- --}}
    <x-sth.modal :id="\App\Livewire\Admin\Kelas::RENAME_DIALOG" size="sm" title="Ganti nama kelas"
                 :description="$renamingName ? 'Kelas '.$renamingName.'. Siswanya tetap di kelas ini.' : null">
        <form id="kelas-ganti-nama-form" wire:submit="rename" class="flex flex-col gap-4" novalidate>
            <x-sth.input wire:model="renameSuffix" maxlength="12" autocomplete="off"
                         :label="$renamingPrefix ? 'Nama kelas setelah '.$renamingPrefix.'-' : 'Nama kelas'"
                         :hint="$renamingPrefix ? 'Tingkat '.$renamingPrefix.' tetap. Huruf, angka, dan spasi, maksimal 12 karakter.' : 'Huruf, angka, dan spasi, maksimal 12 karakter.'" />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="kelas-ganti-nama-form" wire:loading.attr="disabled" wire:target="rename">Simpan nama</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    {{-- "Hapus kelas X-4?": the class and all its students go to the trash. on-close forgets the question. --}}
    <x-sth.modal :id="\App\Livewire\Admin\Kelas::DELETE_DIALOG" size="sm" on-close="forgetDelete"
                 :title="$deleteDialog['title'] ?? 'Hapus kelas?'"
                 description="Penghapusan dicatat di log audit bersama alasannya.">
        @if ($deleteDialog)
            <form id="kelas-hapus-form" wire:submit="delete" class="flex flex-col gap-4" novalidate>
                <div class="flex flex-col gap-2">
                    @foreach ($deleteDialog['lines'] as $line)
                        <p class="m-0 text-md" wire:key="kelas-hapus-baris-{{ $loop->index }}">{{ $line }}</p>
                    @endforeach
                </div>
                <x-sth.textarea wire:model="classReason" id="f-kelas-hapus-alasan" label="Alasan" rows="2"
                                :hint="'Minimal '.$trashReasonMin.' karakter, maksimal '.$trashReasonMax.'. Contoh: kelas digabung ke X-2.'" />
            </form>
        @else
            <p class="m-0 text-md text-muted">Kelas tidak ditemukan. Tutup lalu coba lagi.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($deleteDialog)
                <x-sth.button type="submit" variant="danger" form="kelas-hapus-form" wire:loading.attr="disabled" wire:target="delete">Hapus kelas</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>

    {{-- Students of one class ("Lihat siswa" or the class name). on-close: X, Esc and a click outside forget the class too. --}}
    <x-sth.modal :id="\App\Livewire\Admin\Kelas::STUDENTS_DIALOG" size="lg" on-close="closeStudents"
                 :title="$studentsDialog ? 'Kelas '.$studentsDialog['name'] : 'Siswa kelas'"
                 description="Pilih siswa lalu ubah status akunnya (aktif kembali, nonaktif, atau arsip) atau hapus siswanya.">
        @if ($studentsDialog)
            <div class="flex flex-col gap-3">
                <p class="m-0 text-sm text-muted" aria-live="polite">
                    {{ $studentsDialog['count'] }} siswa, {{ $studentsDialog['active'] }} siswa aktif{{ $studentsDialog['hasMore'] ? ', '.count($studentsDialog['rows']).' tampil' : '' }}.
                </p>

                @if ($studentsDialog['rows'] === [])
                    <x-sth.empty-state icon="users" title="Belum ada siswa di kelas ini" :card="false">Siswa masuk ke kelas lewat import siswa.</x-sth.empty-state>
                @else
                    {{-- SV5: ticks for several students at once; the shared dialog UbahStatusSiswa does the rest. --}}
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="inline-flex min-h-11 cursor-pointer items-center gap-2.5 text-sm font-semibold">
                            <input type="checkbox" class="cek" id="kelas-siswa-pilih-semua" wire:click="toggleAllStudents" @checked($studentsDialog['allSelected'])>
                            Pilih semua yang tampil
                        </label>
                        <span class="ml-auto inline-flex flex-wrap justify-end gap-2">
                            <x-sth.button variant="soft" size="sm" icon="users" aria-haspopup="dialog" wire:click="askStatusSelected"
                                          wire:loading.attr="disabled" wire:target="askStatusSelected" :disabled="$studentsDialog['selected'] === 0">
                                <span>Ubah status terpilih (<span class="num">{{ $studentsDialog['selected'] }}</span>)</span>
                            </x-sth.button>
                            <x-sth.button variant="quiet" size="sm" icon="trash" aria-haspopup="dialog" wire:click="askTrashSelected"
                                          wire:loading.attr="disabled" wire:target="askTrashSelected" :disabled="$studentsDialog['selected'] === 0">
                                <span>Hapus terpilih (<span class="num">{{ $studentsDialog['selected'] }}</span>)</span>
                            </x-sth.button>
                        </span>
                    </div>
                    <x-sth.table :caption="'Siswa kelas '.$studentsDialog['name']" class="overflow-hidden rounded-tile border border-line" :card="false">
                        <x-slot:head>
                            <th scope="col" class="w-12"><span class="sr-only">Pilih</span></th>
                            <th scope="col" class="min-w-44">Siswa dan NISN</th>
                            <th scope="col" class="min-w-28">Status</th>
                            <th scope="col" class="min-w-28"><span class="sr-only">Aksi</span></th>
                        </x-slot:head>
                        @foreach ($studentsDialog['rows'] as $student)
                            <tr wire:key="kelas-siswa-{{ $student['id'] }}">
                                <td>
                                    <label class="inline-flex size-11 cursor-pointer items-center justify-center" for="kelas-siswa-pilih-{{ $student['id'] }}">
                                        <input type="checkbox" class="cek" id="kelas-siswa-pilih-{{ $student['id'] }}" value="{{ $student['id'] }}" wire:model.live="selectedStudents">
                                        <span class="sr-only">Pilih {{ $student['name'] }}</span>
                                    </label>
                                </td>
                                <td>
                                    <div class="flex min-w-0 flex-col [overflow-wrap:anywhere]">
                                        <span class="font-semibold">{{ $student['name'] }}</span>
                                        <span class="text-xs text-muted">NISN <span class="num">{{ $student['nisn'] ?? '–' }}</span></span>
                                    </div>
                                </td>
                                <td><x-sth.chip :tone="$student['tone']" size="sm">{{ $student['status'] }}</x-sth.chip></td>
                                <td class="text-right">
                                    <span class="inline-flex flex-wrap justify-end gap-1">
                                        <x-sth.button variant="quiet" size="sm" aria-haspopup="dialog" wire:click="askStatus({{ $student['id'] }})">
                                            Ubah status<span class="sr-only"> akun {{ $student['name'] }}</span>
                                        </x-sth.button>
                                        <x-sth.button variant="quiet" size="sm" aria-haspopup="dialog" wire:click="askTrashStudent({{ $student['id'] }})">
                                            Hapus<span class="sr-only"> {{ $student['name'] }}</span>
                                        </x-sth.button>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </x-sth.table>
                    @if ($studentsDialog['capped'])
                        <p class="m-0 text-sm text-muted">Menampilkan {{ count($studentsDialog['rows']) }} siswa pertama.</p>
                    @elseif ($studentsDialog['hasMore'])
                        <div class="flex justify-center">
                            <x-sth.button variant="quiet" size="sm" icon="chevron-down" wire:click="showMoreStudents" wire:loading.attr="disabled" wire:target="showMoreStudents">Tampilkan lebih banyak</x-sth.button>
                        </div>
                    @endif
                @endif
            </div>
        @else
            <p class="m-0 text-md text-muted">Kelas tidak ditemukan. Tutup lalu pilih kelas lagi.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Tutup</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    {{-- "Hapus N siswa?" over the student list: the students go to the trash. on-close forgets the question. --}}
    <x-sth.modal :id="\App\Livewire\Admin\Kelas::TRASH_STUDENTS_DIALOG" size="sm" on-close="forgetTrashStudents"
                 :title="$trashStudentsDialog['title'] ?? 'Hapus siswa?'"
                 description="Penghapusan dicatat di log audit bersama alasannya.">
        @if ($trashStudentsDialog)
            <form id="kelas-siswa-hapus-form" wire:submit="trashStudents" class="flex flex-col gap-4" novalidate>
                <div class="rounded-tile bg-subtle px-3 py-2.5">
                    <p class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $trashStudentsDialog['names'] }}</p>
                    @if ($trashStudentsDialog['classroom'])
                        <p class="m-0 text-xs text-muted">Kelas {{ $trashStudentsDialog['classroom'] }}</p>
                    @endif
                </div>
                <div class="flex flex-col gap-2">
                    @foreach ($trashStudentsDialog['lines'] as $line)
                        <p class="m-0 text-md" wire:key="kelas-siswa-hapus-baris-{{ $loop->index }}">{{ $line }}</p>
                    @endforeach
                </div>
                <x-sth.textarea wire:model="studentsReason" id="f-kelas-siswa-hapus-alasan" label="Alasan" rows="2"
                                :hint="'Minimal '.$trashReasonMin.' karakter, maksimal '.$trashReasonMax.'. Contoh: data ganda dari import.'" />
            </form>
        @else
            <p class="m-0 text-md text-muted">Pilih siswa dulu, lalu tekan Hapus.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($trashStudentsDialog)
                <x-sth.button type="submit" variant="danger" form="kelas-siswa-hapus-form" wire:loading.attr="disabled" wire:target="trashStudents">Hapus siswa</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>

    {{-- "Pulihkan" of a Sampah row. on-close forgets the question. --}}
    <x-sth.modal :id="\App\Livewire\Admin\Kelas::RESTORE_DIALOG" size="sm" on-close="forgetRestore"
                 :title="$restoreDialog['title'] ?? 'Pulihkan?'"
                 description="Pemulihan dicatat di log audit.">
        @if ($restoreDialog)
            <div class="flex flex-col gap-2">
                @foreach ($restoreDialog['lines'] as $line)
                    <p class="m-0 text-md" wire:key="kelas-pulihkan-baris-{{ $loop->index }}">{{ $line }}</p>
                @endforeach
            </div>
        @else
            <p class="m-0 text-md text-muted">Data tidak ditemukan. Tutup lalu coba lagi.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($restoreDialog)
                <x-sth.button wire:click="restore" wire:loading.attr="disabled" wire:target="restore">Pulihkan</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>

    {{-- SV5: "Ubah status" of one or several listed students, the dialog shared with Status aktivasi (Admin). --}}
    <livewire:admin.ubah-status-siswa :return-to="route('admin.kelas.index')" :key="'ubah-status-siswa'" />
</div>
