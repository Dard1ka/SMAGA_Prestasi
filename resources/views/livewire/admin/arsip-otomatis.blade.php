{{-- Admin · Arsip otomatis (UI-MAP §5, backlog #8, B5). Numbers and lists come from the component; this view only formats. --}}
@php
    $statusTone = fn ($status) => match ($status) {
        \App\Enums\UserStatus::Active => 'green',
        \App\Enums\UserStatus::PendingActivation => 'orange',
        \App\Enums\UserStatus::Disabled => 'red',
        default => 'gray',
    };
@endphp
<div class="flex flex-col gap-[22px]">
    <div class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-sth.stat-tile icon="clock" tone="red" label="Diarsipkan malam ini" :value="(string) $stats['tonight']" note="tanggal arsip sudah lewat" zero="hide" />
        <x-sth.stat-tile icon="archive" tone="orange" label="Dalam 30 hari" :value="(string) $stats['soon']" note="akun siswa" zero="hide" />
        <x-sth.stat-tile icon="users" tone="gray" label="Sudah diarsipkan" :value="(string) $stats['archived']" note="alumni, tidak bisa login" />
        <x-sth.stat-tile icon="calendar" tone="blue" label="Jadwal arsip" value="01.00 WIB" :note="'setiap hari · '.$stats['months'].' bulan setelah kelas XII'" />
    </div>

    <div class="flex flex-col gap-4">
        <x-sth.tabs label="Arsip otomatis" variant="pill" :active="$tab" :items="$tabs" />

        @if ($tab !== 'riwayat')
            <div class="flex flex-wrap items-end gap-3">
                <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari siswa" hide-label icon="search"
                             placeholder="Nama, atau NISN lengkap" class="w-full sm:w-80" />
                @if ($tab === 'segera')
                    <x-sth.select wire:model.live="hari" label="Rentang" hide-label size="sm" :options="$windowOptions" class="w-36" />
                @endif
                <span class="muted text-sm" wire:loading>Memuat…</span>
            </div>
        @endif

        @if ($tab === 'segera')
            @if ($due->isEmpty())
                <x-sth.empty-state icon="archive" :title="$q !== '' ? 'Tidak ada siswa yang cocok' : 'Tidak ada akun yang segera diarsipkan'">
                    {{ $q !== '' ? 'Ubah pencarian.' : 'Belum ada tanggal arsip dalam '.$hari.' hari ke depan.' }}
                </x-sth.empty-state>
            @else
                <x-sth.table :caption="'Akun siswa yang diarsipkan dalam '.$hari.' hari, '.$due->total().' akun'">
                    <x-slot:head>
                        <th scope="col">Nama</th>
                        <th scope="col">Kelas</th>
                        <th scope="col">Tanggal arsip</th>
                        <th scope="col">Status akun</th>
                        <th scope="col" class="relative"><span class="sr-only">Aksi</span></th>
                    </x-slot:head>
                    @foreach ($due as $student)
                        @php
                            $days = (int) $today->diffInDays($student->archive_at, false);
                        @endphp
                        <tr wire:key="segera-{{ $student->id }}">
                            <td class="font-semibold">{{ $student->user?->name ?? '-' }}</td>
                            <td>{{ $student->classroom?->name ?? '–' }}</td>
                            <td class="whitespace-nowrap">
                                <span class="num">{{ $student->archive_at?->translatedFormat('j M Y') }}</span>
                                @if ($days <= 0)
                                    <x-sth.chip tone="red" size="sm" class="ml-1">malam ini</x-sth.chip>
                                @else
                                    <span class="muted text-sm"> · {{ $days }} hari lagi</span>
                                @endif
                            </td>
                            <td><x-sth.chip :tone="$statusTone($student->user?->status)" size="sm">{{ $student->user?->status?->label() }}</x-sth.chip></td>
                            <td class="text-right">
                                <x-sth.button variant="quiet" size="sm" icon="calendar" wire:click="openDate('{{ $student->id }}')">
                                    Ubah tanggal<span class="sr-only"> arsip {{ $student->user?->name }}</span>
                                </x-sth.button>
                            </td>
                        </tr>
                    @endforeach
                </x-sth.table>
                {{ $due->links() }}
            @endif
        @elseif ($tab === 'arsip')
            @if ($archived->isEmpty())
                <x-sth.empty-state icon="archive" :title="$q !== '' ? 'Tidak ada alumni yang cocok' : 'Belum ada akun yang diarsipkan'">
                    {{ $q !== '' ? 'Ubah pencarian.' : 'Akun siswa diarsipkan otomatis saat tanggal arsipnya tiba.' }}
                </x-sth.empty-state>
            @else
                <x-sth.table :caption="'Akun siswa yang sudah diarsipkan, '.$archived->total().' akun'">
                    <x-slot:head>
                        <th scope="col">Nama</th>
                        <th scope="col">Kelas terakhir</th>
                        <th scope="col">Diarsipkan</th>
                        <th scope="col" class="text-right">Prestasi</th>
                        <th scope="col" class="relative"><span class="sr-only">Aksi</span></th>
                    </x-slot:head>
                    @foreach ($archived as $student)
                        <tr wire:key="arsip-{{ $student->id }}">
                            <td class="font-semibold">{{ $student->user?->name ?? '-' }} <x-sth.chip tone="gray" size="sm" class="ml-1">Alumni</x-sth.chip></td>
                            <td>{{ $student->classroom?->name ?? '–' }}</td>
                            <td class="num whitespace-nowrap">{{ ($archivedOn[$student->id] ?? null)?->translatedFormat('j M Y') ?? '–' }}</td>
                            <td class="num text-right">{{ $student->achievements_count }}</td>
                            <td class="text-right">
                                <x-sth.button variant="soft" size="sm" wire:click="openRestore('{{ $student->id }}')">
                                    Pulihkan<span class="sr-only"> akun {{ $student->user?->name }}</span>
                                </x-sth.button>
                            </td>
                        </tr>
                    @endforeach
                </x-sth.table>
                {{ $archived->links() }}
                <p class="m-0 text-sm muted">Prestasi alumni tetap tampil di peringkat dan halaman publik.</p>
            @endif
        @else
            <div class="grid gap-[22px] xl:grid-cols-3">
                <x-sth.card as="section" aria-labelledby="h-arsip-run" class="flex flex-col gap-3">
                    <div class="flex flex-col gap-1">
                        <h2 id="h-arsip-run" class="title-card">Arsip otomatis</h2>
                        <p class="muted m-0 text-sm">Akun yang diarsipkan per hari, setahun terakhir.</p>
                    </div>
                    @forelse ($runs as $run)
                        <div class="flex items-center justify-between gap-3 border-b border-line-soft pb-2 last:border-0">
                            <span class="num text-sm font-semibold">{{ \Carbon\CarbonImmutable::parse($run['date'])->translatedFormat('j M Y') }}</span>
                            <x-sth.chip tone="gray" size="sm">{{ $run['count'] }} akun</x-sth.chip>
                        </div>
                    @empty
                        <p class="muted m-0 text-sm">Belum ada arsip otomatis.</p>
                    @endforelse
                </x-sth.card>

                <x-sth.card as="section" aria-labelledby="h-arsip-manual" class="flex flex-col gap-3 xl:col-span-2">
                    <div class="flex flex-col gap-1">
                        <h2 id="h-arsip-manual" class="title-card">Perubahan oleh Admin</h2>
                        <p class="muted m-0 text-sm">Pemulihan, perubahan tanggal arsip, dan arsip manual. Semua tercatat di log audit.</p>
                    </div>
                    @forelse ($history as $row)
                        <div wire:key="riwayat-{{ $row['id'] }}" class="flex flex-wrap items-start gap-x-3 gap-y-1 border-b border-line-soft pb-2.5 last:border-0">
                            <x-sth.chip :tone="$row['tone']" size="sm">{{ $row['label'] }}</x-sth.chip>
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="text-sm font-bold">{{ $row['student'] }}@if ($row['to']) <span class="muted font-normal"> · arsip {{ \Carbon\CarbonImmutable::parse($row['to'])->translatedFormat('j M Y') }}</span>@endif</span>
                                <span class="muted text-xs">{{ $row['actor'] }} · {{ $row['at']?->timezone(config('app.timezone'))->translatedFormat('j M Y H:i') }}@if ($row['reason'] !== '') · {{ $row['reason'] }}@endif</span>
                            </span>
                        </div>
                    @empty
                        <p class="muted m-0 text-sm">Belum ada perubahan manual.</p>
                    @endforelse
                </x-sth.card>
            </div>
        @endif
    </div>

    {{-- Dialogs ----------------------------------------------------------------------------------- --}}
    <x-sth.modal id="arsip-tanggal" title="Ubah tanggal arsip" :description="$selected ? 'Akun '.$selected->user?->name.($selected->classroom ? ', kelas '.$selected->classroom->name : '').'.' : null" size="sm">
        <form id="arsip-tanggal-form" wire:submit="saveDate" class="flex flex-col gap-4" novalidate>
            <x-sth.input wire:model="archiveDate" type="date" :min="$minDate" label="Tanggal arsip baru" hint="Contoh: siswa tinggal kelas. Awal kelas XII ikut bergeser." />
            <x-sth.textarea wire:model="archiveReason" label="Alasan" rows="2" />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="arsip-tanggal-form" wire:loading.attr="disabled" wire:target="saveDate">Simpan tanggal</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    <x-sth.modal id="arsip-pulihkan" title="Pulihkan akun?" :description="$selected ? 'Akun '.$selected->user?->name.' bisa login lagi sampai tanggal arsip baru.' : null" size="sm">
        <form id="arsip-pulihkan-form" wire:submit="restore" class="flex flex-col gap-4" novalidate>
            <x-sth.input wire:model="archiveDate" type="date" :min="$minDate" label="Tanggal arsip baru" hint="Harus setelah hari ini. Tanpa tanggal baru, akun diarsipkan lagi malam ini." />
            <x-sth.textarea wire:model="archiveReason" label="Alasan" rows="2" hint="Contoh: siswa masih aktif, tanggal impor salah." />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="arsip-pulihkan-form" wire:loading.attr="disabled" wire:target="restore">Pulihkan akun</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>
</div>
