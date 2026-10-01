{{-- Status aktivasi (K9, K2; UI-MAP §4a/§5). Data prepared in App\Livewire\Akun\StatusAktivasi + App\Actions\Accounts\ActivationReport. --}}
<div class="flex flex-col gap-[22px]">
    <section aria-label="Ringkasan aktivasi" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-sth.stat-tile icon="check-circle" tone="green" label="Sudah aktif" :value="(string) $totals['aktif']" note="akun siswa" />
        <x-sth.stat-tile icon="clock" tone="gray" label="Belum aktivasi" :value="(string) $totals['belum']" note="masih dalam jendela" />
        <x-sth.stat-tile icon="alert" tone="orange" label="Kedaluwarsa" :value="(string) $totals['kedaluwarsa']" note="perlu dibuka ulang" />
        <x-sth.stat-tile icon="lock" tone="red" label="Dibekukan" :value="(string) $totals['dibekukan']" note="gagal masuk atau dilaporkan" />
    </section>

    <div class="grid grid-cols-1 items-start gap-[22px] xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="flex min-w-0 flex-col gap-[22px]">
            {{-- Per kelas --}}
            <section aria-labelledby="h-per-kelas" class="flex flex-col gap-3">
                <x-sth.page-header id="h-per-kelas" title="Per kelas" subtitle="Wali kelas meminta tiap siswa memeriksa barisnya." />
                @if ($summary->isEmpty())
                    <x-sth.empty-state icon="users" title="Belum ada siswa">Impor data siswa dulu.</x-sth.empty-state>
                @else
                    <x-sth.table caption="Status aktivasi per kelas, {{ $summary->count() }} kelas" fixed>
                        <x-slot:head>
                            <th scope="col" class="w-28">Kelas</th>
                            <th scope="col" class="w-16">Siswa</th>
                            <th scope="col" class="w-16">Aktif</th>
                            <th scope="col" class="w-16">Belum</th>
                            <th scope="col" class="w-24">Kedaluwarsa</th>
                            <th scope="col" class="w-24">Dibekukan</th>
                            <th scope="col" class="w-36">Aktif (%)</th>
                            <th scope="col" class="w-24"><span class="sr-only">Aksi</span></th>
                        </x-slot:head>
                        @foreach ($summary as $class)
                            <tr wire:key="kelas-{{ $class['classroomId'] ?? 'none' }}" @class(['bg-primary-wash' => $kelas !== '' && $kelas === $class['classroomId']])>
                                <td class="font-bold">{{ $class['classroom'] }}</td>
                                <td class="num">{{ $class['total'] }}</td>
                                <td class="num">{{ $class['counts']['aktif'] }}</td>
                                <td class="num">{{ $class['counts']['belum'] }}</td>
                                <td class="num">{{ $class['counts']['kedaluwarsa'] }}</td>
                                <td class="num">{{ $class['counts']['dibekukan'] }}</td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <span class="h-1.5 w-16 overflow-hidden rounded-full bg-track" aria-hidden="true">
                                            <span class="block h-full rounded-full bg-green w-pct-{{ max(0, min(100, $class['activePct'])) }}"></span>
                                        </span>
                                        <span class="num text-[13px]">{{ $class['activePct'] }}%</span>
                                    </div>
                                </td>
                                <td>
                                    @if ($class['classroomId'])
                                        <x-sth.button variant="quiet" size="sm" wire:click="showClass('{{ $class['classroomId'] }}')">Lihat</x-sth.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-sth.table>
                @endif
            </section>

            {{-- Daftar siswa --}}
            <section aria-labelledby="h-siswa" class="flex flex-col gap-3">
                <x-sth.page-header id="h-siswa" title="Daftar siswa">
                    <x-slot:actions>
                        <x-sth.button variant="quiet" size="sm" icon="download" wire:click="export" wire:loading.attr="disabled" wire:target="export">Unduh CSV</x-sth.button>
                    </x-slot:actions>
                </x-sth.page-header>

                <x-sth.card padding="none" class="flex min-w-0 flex-col">
                    <div class="flex flex-col gap-3 border-b border-line p-4">
                        <div class="seg flex-wrap" role="group" aria-label="Status">
                            <button type="button" class="seg-btn" wire:click="setStatus('')" aria-pressed="{{ $status === '' ? 'true' : 'false' }}">Semua</button>
                            @foreach ($states as $key => $meta)
                                <button type="button" class="seg-btn" wire:click="setStatus('{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}">
                                    {{ $meta['label'] }} <span class="cnt">{{ $totals[$key] }}</span>
                                </button>
                            @endforeach
                        </div>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-[220px_minmax(0,1fr)_auto] md:items-end">
                            <x-sth.select wire:model.live="kelas" label="Kelas" size="sm" placeholder="Semua kelas" :options="$classes" />
                            <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari" icon="search" placeholder="Nama, atau NIS/NISN lengkap" />
                            @if ($filtersActive)
                                <x-sth.button variant="quiet" size="sm" wire:click="resetFilters">Hapus filter</x-sth.button>
                            @endif
                        </div>
                    </div>

                    <p class="m-0 border-b border-line bg-subtle px-4 py-2.5 text-[13.5px] text-muted" aria-live="polite">
                        {{ $filteredCount }} siswa{{ $hasMore ? ', '.$rows->count().' tampil' : '' }}. Email dan NIS ditampilkan tersamar.
                    </p>

                    @if ($rows->isEmpty())
                        <div class="p-4">
                            <x-sth.empty-state icon="search" title="Tidak ada siswa yang cocok" :card="false">Ubah filter atau kata kunci.</x-sth.empty-state>
                        </div>
                    @else
                        <x-sth.table caption="Daftar status aktivasi siswa" :card="false">
                            <x-slot:head>
                                <th scope="col" class="min-w-56">Siswa</th>
                                <th scope="col" class="min-w-32">Status</th>
                                <th scope="col" class="min-w-40">Email</th>
                                <th scope="col" class="min-w-36">Aktivasi</th>
                                <th scope="col" class="min-w-36">Login terakhir</th>
                                <th scope="col" class="min-w-44">Catatan</th>
                                <th scope="col" class="min-w-40"><span class="sr-only">Aksi</span></th>
                            </x-slot:head>
                            @foreach ($rows as $row)
                                <tr wire:key="siswa-{{ $row['userId'] }}">
                                    <td>
                                        <div class="flex min-w-0 items-center gap-2.5">
                                            <x-sth.avatar :name="$row['name']" size="sm" />
                                            <div class="flex min-w-0 flex-col">
                                                <span class="ellip font-bold">{{ $row['name'] }}</span>
                                                <span class="text-[12.5px] text-muted">{{ $row['classroom'] }} · <span class="num">{{ $row['nis'] }}</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <x-sth.chip :tone="$row['tone']" size="sm">{{ $row['stateLabel'] }}</x-sth.chip>
                                        @if ($row['expiresAt'] && $row['state'] === 'belum')
                                            <span class="mt-1 block text-[12px] text-muted">s.d. {{ $row['expiresAt']->translatedFormat('j M H:i') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-[13.5px]">{{ $row['email'] ?? '–' }}</td>
                                    <td class="num text-[13.5px]">{{ $row['activatedAt']?->translatedFormat('j M Y H:i') ?? '–' }}</td>
                                    <td class="num text-[13.5px]">{{ $row['lastLoginAt']?->translatedFormat('j M Y H:i') ?? '–' }}</td>
                                    <td class="text-[13px]">
                                        @forelse ($row['flags'] as $flag)
                                            <x-sth.chip tone="orange" size="sm" class="h-auto min-h-6 py-0.5 whitespace-normal">{{ $flag }}</x-sth.chip>
                                        @empty
                                            <span class="text-muted">–</span>
                                        @endforelse
                                    </td>
                                    <td>
                                        @if ($row['action'] === 'buka')
                                            <x-sth.button variant="soft" size="sm" wire:click="openReopen({{ $row['userId'] }})">Buka ulang</x-sth.button>
                                        @elseif ($row['action'] === 'cair')
                                            <x-sth.button variant="soft" size="sm" wire:click="openUnfreeze({{ $row['userId'] }})">Buka pembekuan</x-sth.button>
                                        @elseif ($row['action'] === 'admin')
                                            <span class="text-[12.5px] text-muted">Hubungi Admin</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </x-sth.table>
                        @if ($hasMore)
                            <div class="flex justify-center p-4">
                                <x-sth.button variant="quiet" size="sm" wire:click="showMore">Tampilkan lebih banyak</x-sth.button>
                            </div>
                        @endif
                    @endif
                </x-sth.card>
            </section>
        </div>

        <aside class="flex flex-col gap-[22px]" aria-label="Perlu perhatian">
            <x-sth.card as="section" aria-labelledby="h-beku" class="flex flex-col gap-3">
                <x-sth.page-header id="h-beku" title="Akun dibekukan" :level="3" />
                @forelse ($frozen as $account)
                    <div wire:key="beku-{{ $account['id'] }}" class="flex items-start gap-3">
                        <x-sth.avatar :name="$account['name']" size="sm" />
                        <div class="flex min-w-0 flex-1 flex-col">
                            <span class="ellip font-bold">{{ $account['name'] }}</span>
                            <span class="text-[12.5px] text-muted">
                                {{ $account['where'] }} · {{ $account['reason'] === 'not_me' ? 'laporan "Bukan saya"' : 'gagal masuk 10 kali' }}
                                @if ($account['since']) · {{ $account['since']->translatedFormat('j M H:i') }}@endif
                            </span>
                        </div>
                        @if ($account['action'] === 'buka')
                            <x-sth.button variant="quiet" size="sm" wire:click="openReopen({{ $account['id'] }})">Buka ulang</x-sth.button>
                        @elseif ($account['action'] === 'cair')
                            <x-sth.button variant="quiet" size="sm" wire:click="openUnfreeze({{ $account['id'] }})">Buka</x-sth.button>
                        @endif
                    </div>
                @empty
                    <p class="m-0 text-[13.5px] text-muted">Tidak ada akun yang dibekukan.</p>
                @endforelse
            </x-sth.card>

            <x-sth.card as="section" aria-labelledby="h-k7" class="flex flex-col gap-3">
                <x-sth.page-header id="h-k7" title="Kata sandi awal dicoba di akun aktif" :level="3" subtitle="7 hari terakhir. Bisa berarti pemilik asli terkunci." />
                @forelse ($k7 as $i => $alert)
                    <p wire:key="k7-{{ $i }}" class="m-0 text-[13.5px]">
                        <span class="font-bold">{{ $alert['name'] }}</span>
                        <span class="text-muted">{{ $alert['classroom'] ? '· '.$alert['classroom'] : '' }} · {{ $alert['at']->translatedFormat('j M H:i') }}</span>
                    </p>
                @empty
                    <p class="m-0 text-[13.5px] text-muted">Tidak ada.</p>
                @endforelse
            </x-sth.card>

            @if ($canSetWindow)
                <x-sth.card as="section" aria-labelledby="h-jendela" class="flex flex-col gap-3">
                    <x-sth.page-header id="h-jendela" title="Jendela aktivasi" :level="3" subtitle="Lama kata sandi awal berlaku setelah impor. Sekarang {{ $currentWindow }} hari." />
                    <form wire:submit="saveWindow" class="flex items-end gap-2" novalidate>
                        <x-sth.input wire:model="windowDays" type="number" inputmode="numeric" min="1" max="30" label="Hari (1–30)" class="w-32" />
                        <x-sth.button type="submit" variant="soft" size="sm" wire:loading.attr="disabled" wire:target="saveWindow">Simpan</x-sth.button>
                    </form>
                    <p class="m-0 text-[12.5px] text-muted">Berlaku untuk impor berikutnya. Siswa yang sudah diimpor: pakai "Buka ulang" (72 jam).</p>
                </x-sth.card>
            @endif
        </aside>
    </div>

    <x-sth.modal id="aktivasi-aksi" :title="$action === 'cair' ? 'Buka pembekuan akun?' : 'Buka ulang aktivasi?'" size="sm"
        :description="$action === 'cair'
            ? 'Pastikan identitas pemilik akun sudah dicek tatap muka. Jika email akun mencurigakan, pakai Reset kata sandi & email di Pengguna & peran.'
            : 'Kata sandi awal atau sementara siswa berlaku lagi 72 jam. Pastikan siswa siap aktivasi.'">
        <form id="aktivasi-aksi-form" wire:submit="submitAction" class="flex flex-col gap-4" novalidate>
            @if ($target)
                <p class="m-0 font-semibold">{{ $target['name'] }}@if ($target['classroom']) <span class="font-medium text-muted">· {{ $target['classroom'] }}</span>@endif</p>
            @endif
            <x-sth.textarea wire:model="reason" label="Alasan" rows="2" hint="Contoh: siswa sakit saat aktivasi kelas." />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="aktivasi-aksi-form" wire:loading.attr="disabled" wire:target="submitAction">{{ $action === 'cair' ? 'Buka pembekuan' : 'Buka ulang 72 jam' }}</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>
</div>
