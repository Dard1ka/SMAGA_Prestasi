{{-- Siswa · Beranda (boards Beranda-Siswa + Beranda-Mobile). Data: App\Livewire\Siswa\Beranda. --}}
<div class="flex flex-col gap-[22px]">
    <div class="grid grid-cols-1 items-start gap-[22px] lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="flex min-w-0 flex-col gap-[18px]">
            {{-- Hero (blue): greeting, open lomba, points. On phones it is the points banner of Beranda-Mobile. --}}
            <section aria-labelledby="h-halo" class="relative overflow-hidden rounded-card bg-primary p-5 text-white shadow-primary sm:p-6">
                <div class="pointer-events-none absolute -top-16 -right-10 size-56 rounded-full bg-white/10" aria-hidden="true"></div>
                <div class="pointer-events-none absolute -bottom-24 right-40 size-48 rounded-full bg-white/10" aria-hidden="true"></div>
                <div class="relative flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div class="flex flex-col gap-2">
                        <h2 id="h-halo" class="m-0 font-display text-[28px] leading-tight font-extrabold sm:text-[32px]">Halo, {{ $firstName }}</h2>
                        <p class="m-0 text-[15px] text-white/85">
                            @if ($openCount > 0)
                                Ada <span class="num font-bold">{{ $openCount }}</span> lomba yang bisa kamu ikuti sekarang.
                            @else
                                Belum ada lomba yang dibuka. Kamu bisa mengusulkan lomba.
                            @endif
                        </p>
                        <x-sth.button variant="white" size="sm" icon="search" :href="$links['katalog']" class="mt-1 self-start">Cari lomba</x-sth.button>
                    </div>
                    <div class="flex items-center gap-3 self-start rounded-tile bg-white/15 px-4 py-3 sm:self-auto">
                        <span class="inline-flex size-10 items-center justify-center rounded-full bg-white/20" aria-hidden="true"><x-sth.icon name="award" /></span>
                        <div class="flex flex-col">
                            <span class="text-[13px] text-white/80">Poin kamu</span>
                            <span class="flex items-baseline gap-2">
                                <span class="display num text-[26px] leading-none font-extrabold">{{ $points['total'] }}</span>
                                @if ($points['rank'])
                                    <x-sth.chip tone="gold" size="sm" class="num">#{{ $points['rank'] }}</x-sth.chip>
                                @endif
                            </span>
                        </div>
                        @if ($links['peringkat'])
                            <a href="{{ $links['peringkat'] }}" class="ml-1 inline-flex min-h-11 items-center text-sm font-bold text-white underline-offset-4 hover:underline lg:hidden">Peringkat</a>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Perlu tindakan --}}
            @foreach ($actions as $action)
                <x-sth.card as="section" tone="warn" padding="sm" wire:key="{{ $action['key'] }}" aria-labelledby="h-{{ $action['key'] }}"
                            class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div class="flex min-w-0 flex-1 items-center gap-3.5">
                        <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-tile bg-orange-bg text-orange" aria-hidden="true"><x-sth.icon :name="$action['icon']" /></span>
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <x-sth.chip tone="orange" size="sm" class="self-start">Perlu tindakan</x-sth.chip>
                            <h2 id="h-{{ $action['key'] }}" class="m-0 font-sans text-[16.5px] leading-tight font-bold">{{ $action['title'] }}</h2>
                            <p class="num m-0 text-[13.5px] font-semibold text-orange">{{ $action['note'] }}</p>
                        </div>
                    </div>
                    <x-sth.button size="sm" :href="$action['href']" class="self-start sm:self-center">{{ $action['button'] }}</x-sth.button>
                </x-sth.card>
            @endforeach

            {{-- Lomba saya --}}
            <section aria-labelledby="h-lombaku" class="flex flex-col gap-3">
                <x-sth.page-header id="h-lombaku" title="Lomba saya">
                    @if ($links['lombaSaya'])
                        <x-slot:actions><x-sth.link :href="$links['lombaSaya']" icon-right="chevron-right">Lihat semua</x-sth.link></x-slot:actions>
                    @endif
                </x-sth.page-header>
                @if ($mine === [])
                    <x-sth.empty-state icon="clipboard" title="Belum ada lomba yang kamu ikuti" :level="3">
                        Pilih lomba di katalog, lalu daftar sendiri atau bersama tim.
                        <x-slot:actions><x-sth.button size="sm" icon="search" :href="$links['katalog']">Cari lomba</x-sth.button></x-slot:actions>
                    </x-sth.empty-state>
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @foreach ($mine as $item)
                            <x-sth.card as="article" wire:key="reg-{{ $item['id'] }}" class="flex flex-col gap-3">
                                <div class="flex items-center gap-3">
                                    <x-sth.bidang-tile :bidang="$item['bidang']" />
                                    <div class="flex min-w-0 flex-col items-start gap-1">
                                        <h3 class="title-card m-0"><a class="ttl-link" href="{{ $item['href'] }}">{{ $item['title'] }}</a>
                                            <span class="font-sans text-[13px] font-medium text-muted">· {{ $item['meta'] }}</span></h3>
                                        <x-sth.chip :tone="$item['tone']" size="sm">{{ $item['label'] }}</x-sth.chip>
                                    </div>
                                </div>
                                <x-sth.tracker :status="$item['status']" compact />
                            </x-sth.card>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

        <div class="flex min-w-0 flex-col gap-[18px]">
            {{-- Poin prestasi (desktop; phones see the points in the hero) --}}
            <x-sth.card as="section" tone="gold" aria-labelledby="h-poin" class="hidden flex-col gap-4 lg:flex">
                <div class="flex items-center gap-3">
                    <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-medal text-white" aria-hidden="true"><x-sth.icon name="award" /></span>
                    <div class="flex min-w-0 flex-1 flex-col">
                        <h2 id="h-poin" class="m-0 font-sans text-[13px] font-bold tracking-normal text-gold">Poin prestasi</h2>
                        <p class="m-0 flex items-baseline gap-1.5"><span class="display num text-[34px] leading-[1.1] font-extrabold">{{ $points['total'] }}</span><span class="text-sm font-semibold text-gold">poin</span></p>
                    </div>
                    @if ($links['peringkat'])
                        <x-sth.link :href="$links['peringkat']" icon-right="chevron-right" class="self-start text-[13.5px]">Peringkat</x-sth.link>
                    @endif
                </div>
                <dl class="m-0 grid grid-cols-2 gap-3">
                    <div class="flex flex-col gap-0.5 rounded-tile bg-white/70 px-3.5 py-2.5">
                        <dt class="text-[12.5px] font-semibold text-muted">Peringkat</dt>
                        <dd class="display num m-0 text-xl font-extrabold">{{ $points['rank'] ? '#'.$points['rank'] : '–' }}</dd>
                    </div>
                    <div class="flex flex-col gap-0.5 rounded-tile bg-white/70 px-3.5 py-2.5">
                        <dt class="text-[12.5px] font-semibold text-muted">Prestasi</dt>
                        <dd class="display num m-0 text-xl font-extrabold">{{ $points['count'] }}</dd>
                    </div>
                </dl>
            </x-sth.card>

            {{-- Batas daftar terdekat --}}
            <x-sth.card as="section" aria-labelledby="h-batas" class="flex flex-col gap-3">
                <x-sth.page-header id="h-batas" title="Batas daftar terdekat" :level="3">
                    <x-slot:actions><x-sth.link :href="$links['katalog']">Katalog</x-sth.link></x-slot:actions>
                </x-sth.page-header>
                @if ($deadlines === [])
                    <p class="m-0 text-sm text-muted">Belum ada pendaftaran yang dibuka.</p>
                @else
                    <ul class="m-0 flex list-none flex-col gap-1 p-0">
                        @foreach ($deadlines as $row)
                            <li wire:key="batas-{{ $row['id'] }}">
                                <a href="{{ $row['href'] }}" class="flex items-center gap-3 rounded-tile p-2 hover:bg-hover">
                                    <span @class(['flex size-12 shrink-0 flex-col items-center justify-center rounded-tile leading-none', 'bg-orange-bg text-orange' => $row['urgent'], 'bg-page text-ink' => ! $row['urgent']])>
                                        <span class="display num text-lg font-extrabold">{{ $row['day'] }}</span>
                                        <span class="text-[11.5px] font-semibold">{{ $row['month'] }}</span>
                                    </span>
                                    <span class="flex min-w-0 flex-col gap-0.5">
                                        <span class="ellip text-[14.5px] font-bold text-ink">{{ $row['title'] }}</span>
                                        <span class="text-[13px]">
                                            @if ($row['bidang'])<span class="text-muted">{{ $row['bidang'] }} · </span>@endif
                                            <span @class(['num', 'font-bold text-orange' => $row['urgent'], 'text-muted' => ! $row['urgent']])>{{ $row['left'] }}</span>
                                        </span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-sth.card>
        </div>
    </div>

    {{-- Rekomendasi --}}
    @if ($recommendations['cards'] !== [])
        <section aria-labelledby="h-rekom" class="flex flex-col gap-3">
            <x-sth.page-header id="h-rekom" title="Rekomendasi untukmu" :subtitle="$recommendations['subtitle']">
                <x-slot:actions><x-sth.link :href="$links['katalog']" icon-right="chevron-right">Lihat semua lomba</x-sth.link></x-slot:actions>
            </x-sth.page-header>
            <div class="grid grid-cols-1 gap-[18px] sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($recommendations['cards'] as $card)
                    <x-sth.lomba-card wire:key="rekom-{{ $card['id'] }}"
                        :title="$card['title']" :href="$card['href']" :bidang="$card['bidang']" :tingkat="$card['tingkat']"
                        :status="$card['status']" :status-tone="$card['statusTone']" :peserta="$card['peserta']"
                        :penyelenggara="$card['penyelenggara']" :date="$card['date']" :place="$card['place']"
                        :names="$card['names']" :count="$card['count']" :note="$card['note']" />
                @endforeach
            </div>
        </section>
    @endif

    <livewire:siswa.tur-menu :paksa="(bool) session(\App\Livewire\Siswa\TurMenu::FORCE_KEY, false)" />
</div>
