{{-- Portofolio (UI-MAP #14). Data from App\Livewire\Siswa\Portofolio::data(); the view only formats. --}}
<div class="flex flex-col gap-5">
    <x-sth.card as="section" aria-labelledby="profil-nama" class="flex flex-col gap-5 overflow-hidden">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <x-sth.avatar :name="$name" size="xl" />
            <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                <h2 id="profil-nama" class="title-section">{{ $name }}</h2>
                <p class="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <span class="font-semibold">{{ $classroom }}@if ($angkatan) · Angkatan {{ $angkatan }}@endif</span>
                    @if ($activeUntil)
                        <span class="muted">Akun aktif sampai {{ $activeUntil }}</span>
                    @endif
                </p>
                @if ($bidangMinat !== [])
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="label">Bidang minat</span>
                        @foreach ($bidangMinat as $bidang)
                            <x-sth.chip :bidang="$bidang" />
                        @endforeach
                    </div>
                @endif
            </div>
            <x-sth.button variant="quiet" icon="download" wire:click="download" wire:loading.attr="disabled" wire:target="download">
                Unduh portofolio (PDF)
            </x-sth.button>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-sth.stat-tile icon="award" tone="gold" label="Poin kumulatif" :value="$totalPoints" />
            <x-sth.stat-tile icon="bars" tone="blue" label="Peringkat sekolah" :value="$rank !== null ? '#'.$rank : '–'" note="dari siswa aktif" />
            <x-sth.stat-tile icon="trophy" tone="green" label="Prestasi" :value="$achievementCount" />
            <x-sth.stat-tile icon="clipboard" tone="gray" label="Lomba diikuti" :value="$registrationCount" />
        </div>
    </x-sth.card>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div class="flex min-w-0 flex-col gap-4">
            <x-sth.tabs panels id="porto" label="Bagian portofolio" active="prestasi" :items="[
                ['key' => 'prestasi', 'label' => 'Prestasi', 'count' => $achievementCount],
                ['key' => 'riwayat', 'label' => 'Riwayat lomba', 'count' => $registrationCount],
            ]" />

            <x-sth.tab-panel tabs="porto" name="prestasi" active>
                <h2 class="sr-only">Prestasi terverifikasi</h2>
                @if ($achievements === [])
                    <x-sth.empty-state icon="award" title="Belum ada prestasi terverifikasi">
                        Prestasi muncul di sini setelah laporan hasilmu diverifikasi Kesiswaan.
                    </x-sth.empty-state>
                @else
                    <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($achievements as $item)
                            <x-sth.card as="article" padding="none" class="flex flex-col overflow-hidden" aria-labelledby="pr-{{ $item['id'] }}" wire:key="pr-{{ $item['id'] }}">
                                <div @class(['flex h-24 items-start justify-between gap-2 p-3.5', 'cover-'.($item['bidang']?->cssKey() ?? 'sains')])>
                                    <x-sth.chip tone="glass" size="sm">{{ $item['level'] }}</x-sth.chip>
                                    <x-sth.chip tone="gold" icon="award" size="sm">{{ $item['result'] }}</x-sth.chip>
                                </div>
                                <div class="flex flex-1 flex-col gap-2.5 p-4">
                                    @if ($item['bidang'])
                                        <span><x-sth.chip :bidang="$item['bidang']" size="sm" /></span>
                                    @endif
                                    <h3 id="pr-{{ $item['id'] }}" class="title-card">{{ $item['title'] }}</h3>
                                    <div class="flex flex-col text-[13px]">
                                        <span class="muted">{{ $item['organizer'] }}</span>
                                        <span class="muted">{{ $item['date'] }}</span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-sth.chip tone="gold" class="num">{{ $item['points'] }} poin</x-sth.chip>
                                        <span class="muted num text-[13px]">{{ $item['formula'] }}</span>
                                    </div>
                                    @if ($item['certificateUrl'])
                                        <a href="{{ $item['certificateUrl'] }}" class="mt-auto flex items-center gap-3 rounded-tile border border-line p-2.5 text-ink no-underline hover:bg-hover">
                                            <span class="flex size-10 shrink-0 items-center justify-center rounded-control bg-gold-bg text-gold" aria-hidden="true"><x-sth.icon name="file" /></span>
                                            <span class="flex flex-col">
                                                <span class="text-sm font-bold">Sertifikat</span>
                                                <span class="muted text-[12.5px]">Terverifikasi · unduh</span>
                                            </span>
                                            <x-sth.icon name="download" :size="18" class="ml-auto text-muted" />
                                        </a>
                                    @endif
                                </div>
                            </x-sth.card>
                        @endforeach
                    </div>
                @endif
            </x-sth.tab-panel>

            <x-sth.tab-panel tabs="porto" name="riwayat">
                <h2 class="sr-only">Riwayat lomba</h2>
                @if ($history === [])
                    <x-sth.empty-state icon="clipboard" title="Belum ada lomba">Lomba yang kamu ikuti tampil di sini.</x-sth.empty-state>
                @else
                    <x-sth.card padding="none" class="overflow-hidden">
                        <ul class="m-0 list-none divide-y divide-line-soft p-0">
                            @foreach ($history as $row)
                                <li wire:key="rw-{{ $row['id'] }}">
                                    <a href="{{ $row['href'] }}" wire:navigate class="flex items-center gap-3 px-4 py-3 text-ink no-underline hover:bg-hover">
                                        @if ($row['bidang'])
                                            <x-sth.bidang-tile :bidang="$row['bidang']" size="sm" />
                                        @endif
                                        <span class="flex min-w-0 flex-1 flex-col">
                                            <span class="ellip text-sm font-bold">{{ $row['title'] }}</span>
                                            <span class="muted text-[13px]">{{ $row['date'] }}</span>
                                        </span>
                                        <x-sth.chip :tone="$row['status']->tone()" size="sm">{{ $row['status']->label() }}</x-sth.chip>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </x-sth.card>
                @endif
            </x-sth.tab-panel>
        </div>

        <x-sth.card as="aside" aria-labelledby="talenta-judul" class="flex flex-col gap-4">
            <div class="flex items-center justify-between gap-2">
                <h2 id="talenta-judul" class="title-card">Profil talenta</h2>
                <x-sth.chip class="num">{{ count($talents) }} talenta</x-sth.chip>
            </div>
            @if ($talents === [])
                <p class="muted m-0 text-sm">Belum ada talenta. Daftar penjaringan talenta di Profil talenta.</p>
            @else
                <ul class="m-0 flex list-none flex-col gap-3 p-0" aria-label="Talenta pilihanmu">
                    @foreach ($talents as $talent)
                        <li class="flex items-start gap-3">
                            @if ($talent['bidang'])
                                <x-sth.bidang-tile :bidang="$talent['bidang']" size="sm" />
                            @endif
                            <div class="flex min-w-0 flex-col gap-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="display text-[15px] font-bold">{{ $talent['title'] }}</span>
                                    @if ($talent['bidang'])
                                        <x-sth.chip :bidang="$talent['bidang']" size="sm" />
                                    @endif
                                </span>
                                <span><x-sth.chip :tone="$talent['status']->tone()" size="sm">{{ $talent['status']->label() }}</x-sth.chip></span>
                                <span class="muted text-[13px]">{{ $talent['info'] }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
            <x-sth.button variant="soft" size="sm" :href="$talentUrl">Kelola profil talenta</x-sth.button>
        </x-sth.card>
    </div>
</div>
