{{-- Status aktivasi (K9, K2; UI-MAP §4a/§5). Data prepared in App\Livewire\Akun\StatusAktivasi + App\Actions\Accounts\ActivationReport. --}}
<div class="flex flex-col gap-[22px]">
    @include('livewire.partials.resume-download')
    <section aria-label="Ringkasan aktivasi" class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-sth.stat-tile icon="check-circle" tone="green" label="Sudah aktif" :value="(string) $totals['aktif']" note="akun siswa" />
        <x-sth.stat-tile icon="clock" tone="gray" label="Belum aktivasi" :value="(string) $totals['belum']" note="masih dalam jendela" />
        <x-sth.stat-tile icon="alert" tone="orange" label="Kedaluwarsa" :value="(string) $totals['kedaluwarsa']" note="perlu dibuka ulang" zero="hide" />
        <x-sth.stat-tile icon="lock" tone="red" label="Dibekukan" :value="(string) $totals['dibekukan']" note="gagal masuk atau dilaporkan" zero="hide" />
    </section>

    <div class="grid grid-cols-1 items-start gap-[22px] xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="flex min-w-0 flex-col gap-[22px]">
            {{-- Per kelas --}}
            <section aria-labelledby="h-per-kelas" class="flex flex-col gap-3">
                <x-sth.page-header id="h-per-kelas" title="Per kelas" subtitle="Tekan Lihat untuk membuka daftar siswa kelas itu." />
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
                                        <span class="num text-xs">{{ $class['activePct'] }}%</span>
                                    </div>
                                </td>
                                <td>
                                    @if ($class['classroomId'])
                                        <x-sth.button variant="quiet" size="sm" aria-haspopup="dialog" wire:click="showClass('{{ $class['classroomId'] }}')">Lihat<span class="sr-only"> siswa kelas {{ $class['classroom'] }}</span></x-sth.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-sth.table>
                @endif
            </section>

            {{-- Daftar siswa: lookups across classes + CSV. One class at a time is the dialog behind "Lihat". --}}
            <section aria-labelledby="h-siswa" class="flex flex-col gap-3">
                <x-sth.page-header id="h-siswa" title="Daftar siswa" subtitle="Cari siswa di semua kelas.">
                    <x-slot:actions>
                        <x-sth.button variant="quiet" size="sm" icon="download" wire:click="export" wire:loading.attr="disabled" wire:target="export">Download CSV</x-sth.button>
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
                            <x-sth.select wire:model.live="daftarKelas" label="Kelas" size="sm" placeholder="Semua kelas" :options="$classes" />
                            <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari" icon="search" placeholder="Nama, atau NIS/NISN lengkap" />
                            @if ($filtersActive)
                                <x-sth.button variant="quiet" size="sm" wire:click="resetFilters">Hapus filter</x-sth.button>
                            @endif
                        </div>
                    </div>

                    <p class="m-0 border-b border-line bg-subtle px-4 py-2.5 text-sm text-muted" aria-live="polite">
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
                                                <span class="text-xs text-muted">{{ $row['classroom'] }} · <span class="num">{{ $row['nis'] }}</span></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <x-sth.chip :tone="$row['tone']" size="sm">{{ $row['stateLabel'] }}</x-sth.chip>
                                        @if ($row['expiresAt'] && $row['state'] === 'belum')
                                            <span class="mt-1 block text-xs text-muted">s.d. {{ $row['expiresAt']->translatedFormat('j M H:i') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-sm">{{ $row['email'] ?? '–' }}</td>
                                    <td class="num text-sm">{{ $row['activatedAt']?->translatedFormat('j M Y H:i') ?? '–' }}</td>
                                    <td class="num text-sm">{{ $row['lastLoginAt']?->translatedFormat('j M Y H:i') ?? '–' }}</td>
                                    <td class="text-xs">
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
                                            <span class="text-xs text-muted">Hubungi Admin</span>
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
                            <span class="text-xs text-muted">
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
                    <p class="m-0 text-sm text-muted">Tidak ada akun yang dibekukan.</p>
                @endforelse
            </x-sth.card>

            <x-sth.card as="section" aria-labelledby="h-k7" class="flex flex-col gap-3">
                <x-sth.page-header id="h-k7" title="Kata sandi awal dicoba di akun aktif" :level="3" subtitle="7 hari terakhir. Bisa berarti pemilik asli terkunci." />
                @forelse ($k7 as $i => $alert)
                    <p wire:key="k7-{{ $i }}" class="m-0 text-sm">
                        <span class="font-bold">{{ $alert['name'] }}</span>
                        <span class="text-muted">{{ $alert['classroom'] ? '· '.$alert['classroom'] : '' }} · {{ $alert['at']->translatedFormat('j M H:i') }}</span>
                    </p>
                @empty
                    <p class="m-0 text-sm text-muted">Tidak ada.</p>
                @endforelse
            </x-sth.card>

            @if ($canSetWindow)
                <x-sth.card as="section" aria-labelledby="h-jendela" class="flex flex-col gap-3">
                    <x-sth.page-header id="h-jendela" title="Jendela aktivasi" :level="3" subtitle="Lama kata sandi awal berlaku setelah impor. Sekarang {{ $currentWindow }} hari." />
                    <form wire:submit="saveWindow" class="flex items-end gap-2" novalidate>
                        <x-sth.input wire:model="windowDays" type="number" inputmode="numeric" min="1" max="30" label="Hari (1–30)" class="w-32" />
                        <x-sth.button type="submit" variant="soft" size="sm" wire:loading.attr="disabled" wire:target="saveWindow">Simpan</x-sth.button>
                    </form>
                    <p class="m-0 text-xs text-muted">Berlaku untuk impor berikutnya. Siswa yang sudah diimpor: pakai "Buka ulang" (72 jam).</p>
                </x-sth.card>
            @endif
        </aside>
    </div>

    {{-- Students of one class ("Lihat"). Opened by showClass(), or on page load with ?kelas=<id>. --}}
    {{-- on-close: X, Esc and a click outside reset ?kelas= too, not only "Tutup" (QA5 retest, #63). --}}
    <x-sth.modal id="aktivasi-kelas" size="lg" :open="$openClassOnLoad" on-close="closeClass"
        :title="$classDialog ? 'Kelas '.$classDialog['name'] : 'Status aktivasi kelas'"
        description="Email dan NIS ditampilkan tersamar.">
        @if ($classDialog)
            <div class="flex flex-col gap-3">
                <div class="seg flex-wrap self-start" role="group" aria-label="Status siswa kelas {{ $classDialog['name'] }}">
                    @foreach ($classDialog['tabs'] as $tab)
                        <button type="button" class="seg-btn" wire:key="kelas-tab-{{ $tab['key'] ?: 'semua' }}" wire:click="setClassStatus('{{ $tab['key'] }}')" aria-pressed="{{ $kelasStatus === $tab['key'] ? 'true' : 'false' }}">
                            {{ $tab['label'] }} <span class="cnt">{{ $tab['count'] }}</span>
                        </button>
                    @endforeach
                </div>

                <p class="m-0 text-sm text-muted" aria-live="polite">
                    {{ $classDialog['count'] }} siswa{{ $classDialog['hasMore'] ? ', '.$classDialog['rows']->count().' tampil' : '' }}.
                </p>

                @if ($classDialog['rows']->isEmpty())
                    <x-sth.empty-state icon="users" title="Tidak ada siswa dengan status ini" :card="false">Pilih status lain.</x-sth.empty-state>
                @else
                    <x-sth.table caption="Status aktivasi siswa kelas {{ $classDialog['name'] }}" class="overflow-hidden rounded-tile border border-line" :card="false">
                        {{-- Four columns so the table fits the 760px dialog without sideways scrolling: NIS and email sit under the name. --}}
                        <x-slot:head>
                            <th scope="col" class="min-w-44">Siswa, NIS, email</th>
                            <th scope="col" class="min-w-28">Status</th>
                            <th scope="col" class="min-w-40">Aktivasi dan login terakhir</th>
                            <th scope="col" class="min-w-24"><span class="sr-only">Aksi</span></th>
                        </x-slot:head>
                        @foreach ($classDialog['rows'] as $row)
                            <tr wire:key="kelas-siswa-{{ $row['userId'] }}">
                                <td>
                                    <div class="flex min-w-0 flex-col">
                                        <span class="font-bold">{{ $row['name'] }}</span>
                                        <span class="text-xs text-muted">NIS <span class="num">{{ $row['nis'] }}</span></span>
                                        <span class="text-xs text-muted">{{ $row['email'] ?? 'Belum ada email' }}</span>
                                    </div>
                                </td>
                                <td>
                                    <x-sth.chip :tone="$row['tone']" size="sm">{{ $row['stateLabel'] }}</x-sth.chip>
                                    @if ($row['expiresAt'] && $row['state'] === 'belum')
                                        <span class="mt-1 block text-xs text-muted">s.d. {{ $row['expiresAt']->translatedFormat('j M H:i') }}</span>
                                    @endif
                                    @foreach ($row['flags'] as $flag)
                                        <x-sth.chip tone="orange" size="sm" class="mt-1 h-auto min-h-6 py-0.5 whitespace-normal">{{ $flag }}</x-sth.chip>
                                    @endforeach
                                </td>
                                <td class="text-xs">
                                    <span class="block"><span class="text-muted">Aktivasi</span> <span class="num">{{ $row['activatedAt']?->translatedFormat('j M Y H:i') ?? '–' }}</span></span>
                                    <span class="block"><span class="text-muted">Login</span> <span class="num">{{ $row['lastLoginAt']?->translatedFormat('j M Y H:i') ?? '–' }}</span></span>
                                </td>
                                <td>
                                    @if ($row['action'] === 'buka')
                                        <x-sth.button variant="soft" size="sm" aria-haspopup="dialog" wire:click="openReopen({{ $row['userId'] }})">Buka ulang</x-sth.button>
                                    @elseif ($row['action'] === 'cair')
                                        <x-sth.button variant="soft" size="sm" aria-haspopup="dialog" wire:click="openUnfreeze({{ $row['userId'] }})">Buka pembekuan</x-sth.button>
                                    @elseif ($row['action'] === 'admin')
                                        <span class="text-xs text-muted">Hubungi Admin</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-sth.table>
                    @if ($classDialog['hasMore'])
                        <div class="flex justify-center">
                            <x-sth.button variant="quiet" size="sm" wire:click="showMoreClass">Tampilkan lebih banyak</x-sth.button>
                        </div>
                    @endif
                @endif
            </div>
        @else
            <p class="m-0 text-md text-muted">Pilih kelas dari tabel Per kelas.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Tutup</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    {{-- "Buka ulang" says what it will do before the click; the date comes from ReopenActivationWindow::deadlineAfter(). --}}
    <x-sth.modal id="aktivasi-aksi" :title="$action === 'cair' ? 'Buka pembekuan akun?' : 'Buka ulang aktivasi?'" size="sm"
        :description="$action === 'cair'
            ? 'Pastikan identitas pemilik akun sudah dicek tatap muka. Jika email akun mencurigakan, pakai Reset kata sandi & email di Pengguna & peran.'
            : 'Kata sandi awal atau sementara siswa bisa dipakai lagi. Pastikan siswa siap aktivasi.'">
        <form id="aktivasi-aksi-form" wire:submit="submitAction" class="flex flex-col gap-4" novalidate>
            @if ($target)
                <div class="flex flex-col gap-1">
                    <p class="m-0 font-semibold">{{ $target['name'] }}@if ($target['classroom']) <span class="font-medium text-muted">· {{ $target['classroom'] }}</span>@endif</p>
                    @if ($target['reopen'])
                        <p class="m-0 text-md">
                            @if ($target['reopen']['kept'])
                                Pembekuan dibuka. Batas aktivasi tetap <span class="num font-semibold">{{ $target['reopen']['until'] }}</span>.
                            @elseif ($target['reopen']['unfreezes'])
                                Pembekuan dibuka dan aktivasi dibuka lagi sampai <span class="num font-semibold">{{ $target['reopen']['until'] }}</span>.
                            @else
                                Dibuka lagi sampai <span class="num font-semibold">{{ $target['reopen']['until'] }}</span>.
                            @endif
                        </p>
                    @endif
                </div>
            @endif
            <x-sth.textarea wire:model="reason" label="Alasan" rows="2"
                :hint="($action === 'cair' ? '' : 'Minimal 10 karakter. ').'Contoh: siswa sakit saat aktivasi kelas.'" />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="aktivasi-aksi-form" wire:loading.attr="disabled" wire:target="submitAction">{{ $action === 'cair' ? 'Buka pembekuan' : 'Buka ulang' }}</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>
</div>
