{{-- Kepala Sekolah · Persetujuan (mockup Kepsek-Persetujuan.dc.html). Formatting only; rules live in the Actions. --}}
<div class="flex flex-col gap-[22px] lg:flex-row lg:items-start">
    <section class="card flex min-w-0 flex-1 flex-col overflow-hidden p-0" aria-label="Pendaftaran menunggu persetujuan">
        <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2.5 text-sm font-semibold">
                <input type="checkbox" class="cek" wire:click="toggleAll" @checked($allChecked) @disabled($pendingIds === [])>
                Pilih semua
            </label>
            <span class="hidden h-6 w-px bg-line sm:block" aria-hidden="true"></span>
            <x-sth.select wire:model.live="bidang" label="Saring bidang" hide-label size="sm" :options="$bidangOptions" class="w-44" />
            <span class="muted num text-[13.5px]" wire:loading.remove>{{ count($pendingIds) }} menunggu</span>
            <span class="muted text-[13.5px]" wire:loading>Memuat…</span>
            <x-sth.button class="ml-auto" icon="check" wire:click="approveSelected" wire:loading.attr="disabled" :disabled="$checkedCount === 0">
                Setujui terpilih (<span class="num">{{ $checkedCount }}</span>)
            </x-sth.button>
        </div>

        @if ($q !== '')
            <div class="flex flex-wrap items-center gap-2 border-b border-line-soft px-5 py-2.5 text-sm">
                <span class="muted">Hasil pencarian "{{ $q }}"</span>
                <x-sth.button variant="quiet" size="sm" wire:click="$set('q', '')">Hapus pencarian</x-sth.button>
            </div>
        @endif

        @forelse ($rows as $row)
            @php
                $competition = $row->competition;
                $bidangKey = $competition?->bidang?->cssKey() ?? 'sains';
                $state = $recent[$row->id] ?? null;
                $pending = $state === null && $row->status === \App\Enums\RegistrationStatus::MenungguKepsek;
                $members = $row->members;
                $mentor = $row->mentor;
                $mentorName = $mentor ? trim(($mentor->staffProfile?->honorific ? $mentor->staffProfile->honorific.' ' : '').$mentor->name) : 'Belum dipilih';
                $title = $competition?->name.($row->branch ? ' · '.$row->branch : '');
            @endphp
            <article wire:key="reg-{{ $row->id }}" aria-label="{{ $title }}" @class([
                'border-b border-line-soft px-4 py-4 last:border-b-0 sm:px-5',
                'bg-green-bg/50' => $state === 'ok',
                'bg-red-bg/50' => $state === 'tolak',
            ])>
                <div class="flex items-start gap-2">
                    <label class="inline-flex size-11 shrink-0 items-center justify-center">
                        <input type="checkbox" class="cek" value="{{ $row->id }}" wire:model.live="selected" @disabled(! $pending)
                               aria-label="Pilih {{ $title }}">
                    </label>

                    <div class="flex min-w-0 flex-1 flex-col gap-3">
                        <div class="flex flex-wrap items-start gap-3.5">
                            <span class="cover-{{ $bidangKey }} inline-flex size-12 shrink-0 items-center justify-center rounded-tile" aria-hidden="true">
                                <x-sth.icon :name="$bidangKey" :size="24" />
                            </span>
                            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                                <h3 class="m-0 text-[17px] leading-snug font-bold">{{ $title }}</h3>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @if ($competition?->level)
                                        <x-sth.chip>{{ $competition->level->shortLabel() }}</x-sth.chip>
                                    @endif
                                    @if ($competition?->bidang)
                                        <x-sth.chip :bidang="$competition->bidang" />
                                    @endif
                                    @if ($row->isTeam())
                                        <x-sth.chip tone="green" icon="check">Tim {{ $members->count() }} · semua sudah konfirmasi</x-sth.chip>
                                    @else
                                        <x-sth.chip>Individu</x-sth.chip>
                                    @endif
                                </div>
                            </div>

                            @if ($pending)
                                <div class="flex shrink-0 gap-2">
                                    <x-sth.button size="sm" icon="check" wire:click="approve('{{ $row->id }}')" wire:loading.attr="disabled">Setujui</x-sth.button>
                                    <x-sth.button size="sm" variant="quiet" wire:click="openReject('{{ $row->id }}')"
                                                  aria-expanded="{{ $rejecting === $row->id ? 'true' : 'false' }}" aria-controls="tolak-{{ $row->id }}">Tolak</x-sth.button>
                                </div>
                            @elseif ($state === 'ok')
                                <x-sth.chip tone="green" icon="check" class="shrink-0">Disetujui · masuk antrean TU</x-sth.chip>
                            @elseif ($state === 'tolak')
                                <x-sth.chip tone="red" icon="x" class="shrink-0">Ditolak · siswa diberi tahu</x-sth.chip>
                            @endif
                        </div>

                        <dl class="m-0 grid grid-cols-2 gap-4 sm:grid-cols-5 sm:pl-[62px]">
                            <div class="col-span-2 flex min-w-0 flex-col gap-1">
                                <dt class="text-xs font-semibold tracking-wide text-muted uppercase">Peserta</dt>
                                <dd class="m-0 flex min-w-0 items-start gap-2">
                                    <x-sth.avatar-stack :names="$members->map(fn ($m) => $m->student?->user?->name ?? '')->all()" :max="3" />
                                    <span class="min-w-0 text-sm font-semibold">
                                        @foreach ($members as $member)
                                            {{ $member->student?->user?->name }} {{ $member->student?->classroom?->name }}@if ($row->isTeam() && $member->isLeader()) (ketua)@endif{{ $loop->last ? '' : ',' }}
                                        @endforeach
                                    </span>
                                </dd>
                            </div>
                            <div class="flex min-w-0 flex-col gap-1">
                                <dt class="text-xs font-semibold tracking-wide text-muted uppercase">Pembimbing</dt>
                                <dd class="m-0 text-sm font-semibold">{{ $mentorName }}</dd>
                            </div>
                            <div class="flex min-w-0 flex-col gap-1">
                                <dt class="text-xs font-semibold tracking-wide text-muted uppercase">Jadwal lomba</dt>
                                <dd class="m-0 flex flex-col gap-0.5">
                                    <span class="num text-sm font-semibold">{{ $competition?->starts_on?->translatedFormat('j M Y') ?? '-' }}</span>
                                    <span class="muted text-[12.5px]">{{ \App\Livewire\Kepsek\Persetujuan::modeAndPlace($competition) }}</span>
                                </dd>
                            </div>
                            <div class="flex min-w-0 flex-col gap-1">
                                <dt class="text-xs font-semibold tracking-wide text-muted uppercase">Didaftarkan</dt>
                                <dd class="m-0 flex flex-col gap-0.5">
                                    <span class="num text-sm font-semibold">{{ $row->created_at?->translatedFormat('j M') }}</span>
                                    <span class="muted text-[12.5px]">{{ $row->isTeam() ? 'oleh ketua tim' : 'oleh siswa' }}</span>
                                </dd>
                            </div>
                        </dl>

                        @if ($pending && $rejecting === $row->id)
                            <form id="tolak-{{ $row->id }}" wire:submit="reject" class="flex flex-col gap-2 sm:pl-[62px]">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                                    <x-sth.input wire:model="rejectNote" id="alasan-{{ $row->id }}" label="Alasan penolakan (wajib)"
                                                 placeholder="Contoh: jadwal bentrok dengan penilaian tengah semester." maxlength="500" class="flex-1" />
                                    <div class="flex gap-2 sm:pt-7">
                                        <x-sth.button type="submit" variant="danger" size="sm" wire:loading.attr="disabled">Kirim penolakan</x-sth.button>
                                        <x-sth.button variant="quiet" size="sm" wire:click="closeReject">Batal</x-sth.button>
                                    </div>
                                </div>
                            </form>
                        @endif

                        @if ($state === 'tolak' && $row->principal_note)
                            <p class="m-0 text-[13px] text-red sm:pl-[62px]">Alasan: {{ $row->principal_note }}</p>
                        @endif
                    </div>
                </div>
            </article>
        @empty
            <div class="p-5">
                <x-sth.empty-state icon="check-circle" :card="false" :title="$bidang !== '' || $q !== '' ? 'Tidak ada pendaftaran yang cocok' : 'Semua pendaftaran sudah diputuskan'">
                    {{ $bidang !== '' || $q !== '' ? 'Pilih bidang lain atau hapus pencarian.' : 'Pendaftaran baru akan muncul di sini.' }}
                </x-sth.empty-state>
            </div>
        @endforelse

        @if ($more > 0)
            <div class="p-4">
                <x-sth.button variant="quiet" size="sm" block icon="chevron-down" wire:click="tampilkanLebih">Tampilkan lebih banyak ({{ $more }} lagi)</x-sth.button>
            </div>
        @endif
    </section>

    <div class="flex w-full shrink-0 flex-col gap-[18px] lg:w-[300px]">
        <x-sth.card as="section" padding="none" class="flex flex-col gap-3 p-[18px]" aria-labelledby="ringkasan-title">
            <h2 id="ringkasan-title" class="title-card">Ringkasan</h2>
            @foreach ([
                ['Menunggu', $summary['menunggu'], 'bg-orange-bg text-orange', 'clock'],
                ['Disetujui minggu ini', $summary['disetujui'], 'bg-green-bg text-green', 'check-circle'],
                ['Ditolak minggu ini', $summary['ditolak'], 'bg-red-bg text-red', 'x'],
            ] as [$label, $value, $tone, $icon])
                <div class="flex items-center gap-3">
                    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-control {{ $tone }}" aria-hidden="true"><x-sth.icon :name="$icon" /></span>
                    <span class="flex-1 text-sm font-semibold">{{ $label }}</span>
                    <span class="display num text-2xl font-extrabold">{{ $value }}</span>
                </div>
            @endforeach
        </x-sth.card>

        <x-sth.card as="section" padding="none" class="flex flex-col gap-3 p-[18px]" aria-labelledby="terdekat-title">
            <div class="flex items-baseline justify-between gap-3">
                <h2 id="terdekat-title" class="title-card">Lomba terdekat</h2>
                @if (Route::has('kepsek.beranda'))
                    <a class="lnk text-[13.5px]" href="{{ route('kepsek.beranda') }}">Dashboard</a>
                @endif
            </div>
            @forelse ($upcoming as $item)
                <div class="flex items-center gap-3">
                    <span class="flex h-[50px] w-[46px] shrink-0 flex-col items-center justify-center rounded-control border border-line bg-page">
                        <span class="display num text-lg leading-none font-extrabold">{{ $item['day'] }}</span>
                        <span class="text-[11.5px] font-bold tracking-wide text-muted uppercase">{{ $item['month'] }}</span>
                    </span>
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span class="text-sm leading-snug font-bold">{{ $item['name'] }}</span>
                        <span class="muted text-[12.5px]">{{ $item['meta'] }}</span>
                    </span>
                </div>
            @empty
                <p class="muted m-0 text-sm">Belum ada lomba terdekat.</p>
            @endforelse
        </x-sth.card>
    </div>
</div>
