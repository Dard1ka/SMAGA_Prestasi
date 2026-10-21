{{-- Lomba saya: the card for the current phase. $d = App\Livewire\Siswa\LombaSaya\Index::detail(). --}}
@use('App\Enums\RegistrationStatus', 'S')
@use('App\Enums\ReportStatus', 'RS')
@php
    $status = $d['status'];
    $report = $d['report'];
    $registration = $d['registration'];
@endphp

@switch($status)
    @case(S::MenungguLomba)
    @case(S::MenungguKonfirmasi)
    @case(S::MenungguKepsek)
        <x-sth.card as="section" aria-labelledby="setuju-judul" class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-gray-bg text-gray" aria-hidden="true"><x-sth.icon name="clock" /></span>
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    {{-- D-148: a registration whose lomba Kesiswaan has not verified yet does not wait for the approval yet. --}}
                    <h3 id="setuju-judul" class="title-card">{{ $status === S::MenungguLomba ? 'Verifikasi lomba' : 'Persetujuan' }}</h3>
                    <x-sth.chip icon="clock">{{ $status->label() }}</x-sth.chip>
                </div>
                <p class="muted m-0 text-sm">
                    Didaftarkan {{ $d['registeredOn'] }}.
                    @if ($d['waitingText'])
                        {{ $d['waitingText'] }}
                    @else
                        {{ $d['allConfirmed'] ? ($d['isTeam'] ? 'Semua anggota sudah konfirmasi.' : 'Menunggu persetujuan sekolah.') : 'Menunggu konfirmasi anggota tim.' }}
                    @endif
                </p>
            </div>
            @if ($d['canCancel'])
                <x-sth.button variant="danger" size="sm" data-sth-open="batal-daftar" aria-haspopup="dialog">Batalkan pendaftaran</x-sth.button>
            @endif
        </x-sth.card>
        @break

    @case(S::Disetujui)
        <x-sth.card as="section" aria-labelledby="setuju-judul" class="flex items-start gap-3">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-green-bg text-green" aria-hidden="true"><x-sth.icon name="check-circle" /></span>
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 id="setuju-judul" class="title-card">Persetujuan</h3>
                    <x-sth.chip tone="green" icon="check">Disetujui {{ $d['decidedOn'] }}</x-sth.chip>
                </div>
                <p class="muted m-0 text-sm">{{ $d['approvedBy'] ? $d['approvedBy'].' ' : '' }}Tata Usaha sedang menyiapkan surat tugas.</p>
            </div>
        </x-sth.card>
        @break

    @case(S::Ditolak)
        <x-sth.card as="section" aria-labelledby="setuju-judul" class="flex items-start gap-3">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-red-bg text-red" aria-hidden="true"><x-sth.icon name="x" /></span>
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 id="setuju-judul" class="title-card">Persetujuan</h3>
                    <x-sth.chip tone="red">Ditolak {{ $d['decidedOn'] }}</x-sth.chip>
                </div>
                @if ($registration->principal_note)
                    <p class="m-0 text-sm"><span class="font-semibold">Catatan {{ $d['approverRole'] }}:</span> {{ $registration->principal_note }}</p>
                @endif
            </div>
        </x-sth.card>
        @break

    @case(S::Dibatalkan)
        <x-sth.card as="section" aria-labelledby="setuju-judul" class="flex items-start gap-3">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-gray-bg text-gray" aria-hidden="true"><x-sth.icon name="x" /></span>
            <div class="flex min-w-0 flex-col gap-1">
                <h3 id="setuju-judul" class="title-card">Pendaftaran dibatalkan</h3>
                <p class="muted m-0 text-sm">{{ $d['cancelledText'] }}</p>
            </div>
        </x-sth.card>
        @break

    @case(S::SuratTerbit)
    @case(S::Berlangsung)
        <x-sth.card as="section" aria-labelledby="lomba-judul" class="flex items-start gap-3">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-blue-bg text-blue" aria-hidden="true"><x-sth.icon name="trophy" /></span>
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 id="lomba-judul" class="title-card">{{ $status === S::Berlangsung ? 'Lomba berlangsung' : 'Siap berlomba' }}</h3>
                    <x-sth.chip tone="blue">{{ $status->label() }}</x-sth.chip>
                </div>
                {{-- D-151: no report deadline. --}}
                <p class="muted m-0 text-sm">Setelah lomba selesai, laporkan hasil lombamu di sini.</p>
            </div>
        </x-sth.card>
        @break

    @case(S::MenungguLaporan)
        <x-sth.card as="section" aria-labelledby="lapor-judul" :tone="$d['canReport'] ? 'warn' : null" class="flex flex-col gap-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-orange-bg text-orange" aria-hidden="true"><x-sth.icon name="upload" /></span>
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 id="lapor-judul" class="title-card">{{ $report?->status === RS::PerluRevisi ? 'Perbaiki laporan' : 'Laporkan hasil' }}</h3>
                        @if ($d['reportChip'])
                            <x-sth.chip :tone="$d['reportChip']['tone']" icon="clock">{{ $d['reportChip']['chip'] }}</x-sth.chip>
                        @endif
                    </div>
                    <p class="muted m-0 text-sm">
                        @if ($d['canReport'])
                            {{ $d['reportPrompt'] }} Upload sertifikat dan foto dokumentasi lombamu.
                        @else
                            Ketua tim mengirim laporan hasil untuk seluruh tim.
                        @endif
                    </p>
                </div>
                @if ($d['canReport'])
                    {{-- UX2: one place to report at every width; the Beranda button opens this card with ?lapor=1. --}}
                    <div class="flex shrink-0 gap-2">
                        @if ($lapor)
                            <x-sth.button variant="quiet" size="sm" wire:click="closeLapor">Batal</x-sth.button>
                        @else
                            <x-sth.button icon="upload" wire:click="openLapor">{{ $report?->status === RS::PerluRevisi ? 'Perbaiki laporan' : 'Laporkan hasil' }}</x-sth.button>
                        @endif
                    </div>
                @endif
            </div>

            @if ($report?->status === RS::PerluRevisi && $report->note)
                <x-sth.alert tone="warning" title="Catatan {{ $d['decider'] }}">{{ $report->note }}</x-sth.alert>
            @endif
            @if ($d['isTeam'])
                <p class="m-0 inline-flex items-center gap-2 text-sm text-ink-2"><x-sth.icon name="users" :size="18" />Setiap anggota tim mendapat poin penuh.</p>
            @endif

            @if ($d['canReport'] && $lapor)
                <div class="border-t border-line pt-4">
                    <livewire:siswa.lomba-saya.lapor-hasil :registration="$registration" :inline="true" :key="'lapor-'.$registration->getKey()" />
                </div>
            @endif
        </x-sth.card>
        @break

    @case(S::LaporanDikirim)
        <x-sth.card as="section" aria-labelledby="lapor-ok" class="flex items-start gap-3">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-blue-bg text-blue" aria-hidden="true"><x-sth.icon name="send" /></span>
            <div class="flex min-w-0 flex-col gap-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 id="lapor-ok" class="title-card">Laporan terkirim</h3>
                    <x-sth.chip tone="blue">Laporan dikirim</x-sth.chip>
                </div>
                @if ($report)
                    <p class="muted m-0 text-sm">
                        {{ $report->claimed_result->label() }}@if ($d['estimate']) · perkiraan <span class="num">{{ app(\App\Domain\Points\PointFormatter::class)->format($d['estimate']->points) }}</span> poin{{ $d['isTeam'] ? ' untuk setiap anggota' : '' }}@endif.
                        Sekolah akan memverifikasi laporanmu.
                    </p>
                @endif
            </div>
        </x-sth.card>
        @break

    @case(S::Selesai)
        @if ($report?->status === RS::Ditolak)
            <x-sth.card as="section" aria-labelledby="capaian-judul" class="flex items-start gap-3">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-red-bg text-red" aria-hidden="true"><x-sth.icon name="x" /></span>
                <div class="flex min-w-0 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 id="capaian-judul" class="title-card">Laporan ditolak</h3>
                        <x-sth.chip tone="red">Tanpa poin</x-sth.chip>
                    </div>
                    @if ($report->note)
                        <p class="m-0 text-sm"><span class="font-semibold">Catatan {{ $d['decider'] }}:</span> {{ $report->note }}</p>
                    @endif
                </div>
            </x-sth.card>
        @elseif ($d['achievement'])
            @php($a = $d['achievement'])
            <div class="grid grid-cols-1 gap-4 2xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
                <x-sth.card as="section" aria-labelledby="capaian-judul" class="flex flex-col gap-4">
                    <div class="flex items-start gap-3">
                        <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-gold-bg text-gold" aria-hidden="true"><x-sth.icon name="award" /></span>
                        <div class="flex min-w-0 flex-col gap-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 id="capaian-judul" class="title-card">Capaian</h3>
                                <x-sth.chip tone="gold" icon="award">{{ $a->result->label() }}</x-sth.chip>
                                <x-sth.chip tone="green" icon="check">Poin diberikan</x-sth.chip>
                            </div>
                            <p class="muted m-0 text-sm">Diverifikasi {{ $d['decider'] }} {{ $a->verified_at?->locale('id')->translatedFormat('j M Y') }}.</p>
                        </div>
                    </div>
                    {{-- D-160: the verifier's "Catatan untuk siswa" (e.g. why the capaian differs from the claim). --}}
                    @if ($report?->note)
                        <p class="m-0 text-sm [overflow-wrap:anywhere]"><span class="font-semibold">Catatan {{ $d['decider'] }}:</span> {{ $report->note }}</p>
                    @endif
                    <x-sth.rumus-poin :tingkat="$a->w_level" :capaian="$a->w_result" :penyelenggara="$a->w_organizer" :total="$a->points" result-label="Total" />
                </x-sth.card>
                <x-sth.card as="section" aria-labelledby="sert-judul" class="flex flex-col gap-3">
                    <h3 id="sert-judul" class="title-card">Sertifikat</h3>
                    <p class="muted m-0 text-sm">Terverifikasi. Hanya kamu, tim, dan sekolah yang bisa men-download.</p>
                    @if ($d['certificate'])
                        <x-sth.button variant="quiet" icon="download" :href="$d['certificate']">Download sertifikat</x-sth.button>
                    @endif
                </x-sth.card>
            </div>
        @else
            <x-sth.card as="section" aria-labelledby="capaian-judul" class="flex flex-col gap-1">
                <h3 id="capaian-judul" class="title-card">Selesai</h3>
                <p class="muted m-0 text-sm">Laporan sudah diverifikasi {{ $d['decider'] }}.</p>
            </x-sth.card>
        @endif
        @break
@endswitch
