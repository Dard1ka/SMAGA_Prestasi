{{-- Admin · Kelas (App\Livewire\Admin\Kelas). Groups, counts and names come from the component; this view only formats. --}}
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
                            <span class="font-semibold [overflow-wrap:anywhere]">{{ $row['name'] }}</span>
                            @unless ($row['enabled'])
                                <x-sth.chip tone="gray" size="sm" class="ml-1">Tidak aktif</x-sth.chip>
                            @endunless
                        </td>
                        <td class="num text-right">{{ $row['active'] }}</td>
                        <td class="num text-right">{{ $row['total'] }}</td>
                        <td class="text-right">
                            <span class="inline-flex flex-wrap justify-end gap-2">
                                <x-sth.button variant="quiet" size="sm" icon="edit" wire:click="askRename('{{ $row['id'] }}')">
                                    Ganti nama<span class="sr-only"> kelas {{ $row['name'] }}</span>
                                </x-sth.button>
                                @if ($row['total'] > 0)
                                    <x-sth.button variant="quiet" size="sm" icon="trash" disabled>
                                        Hapus<span class="sr-only"> kelas {{ $row['name'] }}: masih berisi {{ $row['total'] }} siswa</span>
                                    </x-sth.button>
                                @else
                                    <x-sth.button variant="quiet" size="sm" icon="trash" wire:click="askDelete('{{ $row['id'] }}')">
                                        Hapus<span class="sr-only"> kelas {{ $row['name'] }}</span>
                                    </x-sth.button>
                                @endif
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
        <p class="muted m-0 text-sm">Kelas yang masih berisi siswa, juga alumni, tidak bisa dihapus. Pindahkan siswanya lewat import siswa dulu. Tingkat kelas tidak bisa diganti karena tanggal arsip siswanya mengikuti tingkat.</p>
    @endif

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

    <x-sth.modal :id="\App\Livewire\Admin\Kelas::DELETE_DIALOG" size="sm" title="Hapus kelas ini?"
                 description="Kelas hilang dari daftar dan tidak bisa dipakai import siswa lagi.">
        @if ($deletingName)
            <div class="rounded-tile bg-subtle px-3 py-2.5">
                <p class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $deletingName }}</p>
                <p class="m-0 text-xs text-muted">Belum ada siswa di kelas ini.</p>
            </div>
        @else
            <p class="m-0 text-md text-muted">Kelas tidak ditemukan. Tutup lalu coba lagi.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($deletingName)
                <x-sth.button variant="danger" wire:click="delete" wire:loading.attr="disabled" wire:target="delete">Hapus kelas</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
