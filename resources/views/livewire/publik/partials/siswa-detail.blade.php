{{--
    Level 3 of the drill-down and /prestasi/siswa/{slug}: student header (total points, rank) + every
    verified achievement sorted by points. Data: PublicAchievements::studentDetail() (consenting students
    only). Certificates: a `files.public` link only when FilePolicy::viewPublic allows it (Admin switch +
    publish consent of every team member, decided in PublicAchievements), else just the badge.

    @include('livewire.publik.partials.siswa-detail', ['detail' => $detail, 'level' => 1])
    level: heading level of the student name (1 on the student page, 2 inside Prestasi per bidang)
--}}
@php
    $level = (int) ($level ?? 2);
    $hName = 'h'.$level;
    $hSection = 'h'.($level + 1);
    $hItem = 'h'.($level + 2);
    $rank = $detail['alumni'] ? $detail['rank_all'] : $detail['rank_active'];
    $rankNote = $detail['alumni'] ? 'termasuk alumni' : 'siswa aktif';
    $fileRoute = \Illuminate\Support\Facades\Route::has('files.public');
@endphp
<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-4">
        <x-sth.avatar :name="$detail['name']" size="xl" />
        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
            <{{ $hName }} @class(['m-0 font-display leading-tight font-bold break-words', 'text-2xl md:text-3xl' => $level === 1, 'text-xl' => $level !== 1])>{{ $detail['name'] }}</{{ $hName }}>
            <div class="flex flex-wrap items-center gap-2">
                @if ($detail['alumni'])
                    {{-- An alumnus has no class any more: the last class is not shown (lead, 2026-10-04). --}}
                    <x-sth.chip>Alumni{{ $detail['lulus'] ? ' · lulus '.$detail['lulus'] : '' }}</x-sth.chip>
                @else
                    <span class="text-sm font-semibold text-ink-2">Kelas {{ $detail['kelas'] }}</span>
                @endif
            </div>
        </div>
        {{-- Phones: the two number tiles take their own row, so the name keeps the full width. --}}
        <dl class="m-0 flex w-full gap-2.5 sm:w-auto">
            <div class="flex flex-1 flex-col gap-px rounded-tile bg-gold-bg px-3 py-2.5 text-gold sm:w-28 sm:flex-none">
                <dt class="text-xs font-semibold">Total poin</dt>
                <dd class="display num m-0 text-2xl leading-7 font-bold">{{ $detail['points_label'] }}</dd>
                <dd class="m-0 text-xs">semua tahun</dd>
            </div>
            <div class="flex flex-1 flex-col gap-px rounded-tile bg-page px-3 py-2.5 sm:w-[120px] sm:flex-none">
                <dt class="text-xs font-semibold text-muted">Peringkat</dt>
                <dd class="display num m-0 text-2xl leading-7 font-bold text-ink">{{ $rank !== null ? '#'.$rank : '–' }}</dd>
                <dd class="m-0 text-xs text-muted">{{ $rankNote }}</dd>
            </div>
        </dl>
    </div>

    <div class="h-px bg-line" aria-hidden="true"></div>

    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <{{ $hSection }} class="title-card">Lomba &amp; capaian</{{ $hSection }}>
        <span class="num text-xs font-semibold text-muted">{{ $detail['count'] }} prestasi terverifikasi</span>
    </div>

    <ul class="m-0 flex list-none flex-col gap-2 p-0">
        @foreach ($detail['achievements'] as $item)
            <li wire:key="ach-{{ $loop->index }}-{{ $item['competition'] }}" class="grid gap-3 rounded-card border border-line bg-surface px-3.5 py-3 sm:grid-cols-[48px_minmax(0,1fr)_auto] sm:items-start">
                <span class="hidden sm:block"><x-sth.bidang-tile :bidang="$item['bidang']" /></span>
                <div class="flex min-w-0 flex-col gap-1 [overflow-wrap:anywhere]">
                    <{{ $hItem }} class="m-0 text-md leading-5 font-bold">
                        <a href="{{ route('public.lomba.pemenang', $item['competition']) }}" class="text-ink hover:text-primary">{{ $item['lomba'] }}</a>
                    </{{ $hItem }}>
                    <span class="text-xs leading-[18px] text-muted">
                        @if ($item['organizer'] !== ''){{ $item['organizer'] }} ({{ $item['kategori_label'] }}) · @endif Tahun ajaran {{ $item['tahun'] }}
                    </span>
                    <div class="flex flex-wrap gap-1.5 pt-1">
                        <x-sth.chip size="sm">{{ $item['level_label'] }}</x-sth.chip>
                        <x-sth.chip size="sm" tone="gold">{{ $item['result_label'] }}</x-sth.chip>
                        <x-sth.chip size="sm" :bidang="$item['bidang']" />
                    </div>
                </div>
                <div class="flex flex-row flex-wrap items-center gap-x-3 gap-y-1.5 sm:flex-col sm:items-end">
                    <span class="inline-flex items-baseline gap-1 rounded-control bg-gold-bg px-2.5 py-1 text-xs font-bold text-gold">
                        <span class="display num text-xl leading-[22px] font-bold">{{ $item['points_label'] }}</span>poin
                    </span>
                    <span class="num text-xs whitespace-nowrap text-muted"><span class="sr-only">Rumus poin: tingkat × capaian × penyelenggara, </span>{{ $item['rumus'] }}</span>
                    {{-- Plain text, not a tag: everything on this page is verified, so it must not compete with the result. --}}
                    <span class="inline-flex items-center gap-1 text-xs font-semibold whitespace-nowrap text-green"><x-sth.icon name="check" :size="14" />Terverifikasi {{ $item['verified_label'] }}</span>
                    @if ($item['certificate'] !== null && $fileRoute)
                        <x-sth.link :href="route('files.public', $item['certificate'])" class="text-xs">Lihat sertifikat<span class="sr-only"> {{ $item['lomba'] }}</span></x-sth.link>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>
