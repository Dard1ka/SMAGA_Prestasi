{{-- Kelola lomba & antrean verifikasi (board Kesiswaan-Kelola-Lomba). Data prepared in App\Livewire\Kesiswaan\Lomba\Index. --}}
<div class="flex flex-col gap-[22px]">
    <section aria-label="Ringkasan" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $stat)
            <x-sth.stat-tile :icon="$stat['icon']" :tone="$stat['tone']" :label="$stat['label']" :value="$stat['value']" :note="$stat['note']" :href="$stat['href']" />
        @endforeach
    </section>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-sth.tabs label="Daftar lomba" variant="pill" :active="$tab" :items="[
            ['key' => 'katalog', 'label' => 'Katalog', 'count' => $catalogCount, 'attributes' => ['wire:click' => 'setTab(\'katalog\')']],
            ['key' => 'verifikasi', 'label' => 'Antrean verifikasi', 'count' => $pendingCount, 'attributes' => ['wire:click' => 'setTab(\'verifikasi\')']],
        ]" />
        <div class="flex w-full flex-wrap items-center gap-3 sm:w-auto">
            @if ($tab === 'katalog')
                <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari lomba" hide-label icon="search" placeholder="Cari nama lomba atau penyelenggara" class="w-full sm:w-80" />
            @endif
            <x-sth.button icon="plus" wire:click="create" aria-haspopup="dialog">Tambah lomba</x-sth.button>
        </div>
    </div>

    @if ($tab === 'katalog')
        <div class="flex flex-wrap gap-2" role="group" aria-label="Filter status">
            @foreach ($statusFilters as $value => $label)
                <button type="button" class="fchip" wire:click="setStatus('{{ $value }}')" aria-pressed="{{ $status === (string) $value ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>

        @if ($rows->isEmpty())
            <x-sth.empty-state icon="search" title="Belum ada lomba yang cocok">
                Ubah filter atau tambah lomba baru.
            </x-sth.empty-state>
        @else
            <x-sth.table caption="Katalog lomba SMAGA, {{ $rows->count() }} lomba ditampilkan" fixed>
                <x-slot:head>
                    <th scope="col" class="w-[36%] min-w-[280px]">Lomba</th>
                    <th scope="col" class="w-32">Bidang</th>
                    <th scope="col" class="w-24">Tingkat</th>
                    <th scope="col" class="w-32">Batas daftar</th>
                    <th scope="col" class="w-24">Pendaftar</th>
                    <th scope="col" class="w-48">Status</th>
                    <th scope="col" class="w-24"><span class="sr-only">Aksi</span></th>
                </x-slot:head>
                @foreach ($rows as $row)
                    <tr wire:key="lomba-{{ $row['id'] }}">
                        <td>
                            <div class="flex min-w-0 items-center gap-3">
                                @if ($row['bidang'])
                                    <x-sth.bidang-tile :bidang="$row['bidang']" size="sm" />
                                @endif
                                <div class="flex min-w-0 flex-col gap-0.5">
                                    <span class="leading-[1.35] font-bold">{{ $row['name'] }}</span>
                                    <span class="ellip text-[12.5px] text-muted">{{ $row['sub'] }}</span>
                                </div>
                            </div>
                        </td>
                        <td>@if ($row['bidang'])<x-sth.chip :bidang="$row['bidang']" />@else – @endif</td>
                        <td>{{ $row['tingkat'] }}</td>
                        <td>
                            <div class="flex flex-col gap-0.5">
                                <span class="num">{{ $row['batas'] }}</span>
                                @if ($row['soon'])<span class="text-[12.5px] font-bold text-orange">{{ $row['soon'] }}</span>@endif
                            </div>
                        </td>
                        <td class="num">{{ $row['count'] }} siswa</td>
                        <td><x-sth.chip :tone="$row['status'][0]" :icon="$row['status'][1]">{{ $row['status'][2] }}</x-sth.chip></td>
                        <td>
                            <div class="flex items-center gap-1.5">
                                @if ($row['canEdit'])
                                    <x-sth.icon-button icon="edit" size="sm" :label="'Ubah '.$row['name']" wire:click="edit('{{ $row['id'] }}')" />
                                @endif
                                @if ($row['canClose'])
                                    <x-sth.icon-button icon="archive" size="sm" :label="'Tutup '.$row['name']" wire:click="close('{{ $row['id'] }}')" wire:confirm="Tutup lomba ini? Lomba tidak tampil lagi di katalog siswa." />
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-sth.table>
            @if ($hasMore)
                <div class="flex justify-center">
                    <x-sth.button variant="quiet" size="sm" wire:click="showMore">Tampilkan lebih banyak</x-sth.button>
                </div>
            @endif
        @endif
    @else
        <x-sth.alert>Semua upload lomba, termasuk dari Kesiswaan sendiri, diperiksa di sini sebelum tampil di katalog.</x-sth.alert>

        @if ($queue->isEmpty())
            <x-sth.empty-state icon="inbox" title="Antrean kosong">Tidak ada lomba yang menunggu verifikasi.</x-sth.empty-state>
        @else
            <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 xl:grid-cols-2" aria-label="Lomba menunggu verifikasi">
                @foreach ($queue as $item)
                    <x-sth.card as="li" class="flex flex-col gap-4" wire:key="usulan-{{ $item['id'] }}">
                        <div class="flex items-start gap-3">
                            <x-sth.avatar :name="$item['submitter']" size="md" />
                            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="font-bold">{{ $item['submitter'] }}</span>
                                <span class="text-[13px] text-muted">{{ $item['submitterMeta'] }} · {{ $item['when'] }}</span>
                            </div>
                            <x-sth.chip :tone="$item['source'][0]" size="sm">{{ $item['source'][1] }}</x-sth.chip>
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($item['bidang'])<x-sth.chip :bidang="$item['bidang']" size="sm" />@endif
                                <h3 class="title-card m-0">{{ $item['name'] }}</h3>
                            </div>
                            <p class="m-0 text-[13.5px] text-muted">{{ $item['organizer'] ?? 'Penyelenggara belum diisi' }}</p>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                @if ($item['website'])
                                    <x-sth.link :href="$item['website']" icon="link" external>{{ $item['websiteHost'] }}</x-sth.link>
                                @else
                                    <span class="text-[13px] text-muted">Tanpa tautan</span>
                                @endif
                                @if ($item['juknis'])
                                    @if ($item['juknis']['url'])
                                        <x-sth.link :href="$item['juknis']['url']" icon="file">{{ $item['juknis']['name'] }} · {{ $item['juknis']['size'] }}</x-sth.link>
                                    @else
                                        <span class="text-[13px] text-muted">{{ $item['juknis']['name'] }} · {{ $item['juknis']['size'] }}</span>
                                    @endif
                                @endif
                            </div>
                        </div>

                        @if ($item['missing'] === [])
                            <x-sth.chip tone="green" icon="check" class="self-start">Data lengkap, siap dipublikasikan</x-sth.chip>
                        @else
                            <p class="m-0 text-[13px] text-orange"><strong>Perlu dilengkapi:</strong> {{ implode(', ', $item['missing']) }}</p>
                        @endif

                        @if ($rejectingId === $item['id'])
                            <form wire:submit="reject" class="flex flex-col gap-3">
                                <x-sth.textarea wire:model="rejectNote" label="Alasan penolakan" rows="2" placeholder="Contoh: Lomba sudah lewat atau info tidak resmi." />
                                <div class="flex flex-wrap gap-2">
                                    <x-sth.button type="submit" variant="danger" size="sm" icon="send">Kirim penolakan</x-sth.button>
                                    <x-sth.button variant="quiet" size="sm" wire:click="cancelReject">Batal</x-sth.button>
                                </div>
                            </form>
                        @elseif ($item['canVerify'])
                            <div class="flex flex-wrap gap-2">
                                @if ($item['canEdit'])
                                    <x-sth.button size="sm" variant="soft" icon="edit" wire:click="edit('{{ $item['id'] }}')" aria-haspopup="dialog">Periksa & lengkapi</x-sth.button>
                                @endif
                                @if ($item['missing'] === [])
                                    <x-sth.button size="sm" icon="check" wire:click="publish('{{ $item['id'] }}')">Publikasikan</x-sth.button>
                                @endif
                                <x-sth.button size="sm" variant="danger" icon="x" wire:click="startReject('{{ $item['id'] }}')">Tolak</x-sth.button>
                            </div>
                        @endif
                    </x-sth.card>
                @endforeach
            </ul>
        @endif
    @endif

    <livewire:kesiswaan.lomba.form />
</div>
