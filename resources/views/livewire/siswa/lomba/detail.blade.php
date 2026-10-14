{{-- Siswa · Detail lomba (board Detail-Lomba). Data from App\Livewire\Siswa\Lomba\Detail; the panel is Siswa\Pendaftaran\Form. --}}
@php($bidangKey = $competition->bidang?->cssKey() ?? 'sains')
<div class="flex flex-col gap-[18px]">
    <x-slot:lead>
        <nav aria-label="Breadcrumb">
            <ol class="m-0 flex list-none flex-wrap items-center gap-1.5 p-0 text-md">
                <li><a class="lnk text-md" href="{{ route('siswa.lomba.index') }}"><x-sth.icon name="chevron-left" :size="18" />Cari lomba</a></li>
                <li class="inline-flex text-placeholder" aria-hidden="true"><x-sth.icon name="chevron-right" :size="16" /></li>
                <li aria-current="page" class="ellip max-w-[46vw] font-semibold text-ink-2">{{ $competition->name }}</li>
            </ol>
        </nav>
    </x-slot:lead>

    {{-- QA10: one text per status (closed early, rejected, waiting for Kesiswaan), from LombaPresenter::unpublishedNotice(). --}}
    @if ($notice)
        <x-sth.alert tone="warning" :title="$notice['title']">{{ $notice['text'] }}</x-sth.alert>
    @endif

    <div class="grid grid-cols-1 items-start gap-[18px] lg:grid-cols-[minmax(0,1fr)_380px]">
        <div class="flex min-w-0 flex-col gap-[18px]">
            {{-- Head: the poster on the left (whole; "Perbesar" opens the file in the preview popup), beside it the
                 status, the name, who organises it, who registered and the five key facts. The rest stays below. --}}
            <x-sth.lomba-head :bidang="$bidangKey" :seed="$competition->getKey()" :poster="$poster" :facts="$facts"
                              alt="Poster {{ $competition->name }}" aria-labelledby="h-lomba">
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                    <x-sth.chip :tone="$window['tone']">{{ $window['label'] }}</x-sth.chip>
                    @if ($competition->bidang)
                        <x-sth.chip :bidang="$competition->bidang" />
                    @endif
                </div>
                <h1 id="h-lomba" class="title-page m-0 text-xl @min-[460px]:text-2xl @min-[640px]:text-3xl">{{ $competition->name }}</h1>
                <p class="m-0 text-sm text-muted">{{ $penyelenggara }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($registrants !== [])
                        <x-sth.avatar-stack :names="$registrants" />
                        <span class="text-xs text-muted"><span class="num">{{ count($registrants) }}</span> siswa SMAGA sudah mendaftar</span>
                    @else
                        <span class="text-xs text-muted">Belum ada siswa SMAGA yang mendaftar</span>
                    @endif
                </div>
            </x-sth.lomba-head>

            <x-sth.card as="section" aria-label="Info lomba" class="flex flex-col gap-4">
                <x-sth.tabs panels id="info-lomba" label="Info lomba" active="tentang" :items="[
                    ['key' => 'tentang', 'label' => 'Tentang'],
                    ['key' => 'persyaratan', 'label' => 'Persyaratan'],
                    ['key' => 'jadwal', 'label' => 'Jadwal'],
                    ['key' => 'dokumen', 'label' => 'Dokumen'],
                ]" />

                <x-sth.tab-panel tabs="info-lomba" name="tentang" active>
                    <div class="flex flex-col gap-4">
                        @forelse ($paragraphs as $paragraph)
                            <p class="m-0 leading-relaxed">{{ $paragraph }}</p>
                        @empty
                            <p class="m-0 text-muted">Deskripsi belum ada. Lihat juknis atau website resmi di tab Dokumen.</p>
                        @endforelse
                        <div class="flex flex-wrap gap-2">
                            @if ($competition->isPublished())
                                <x-sth.chip tone="green" icon="check">Diverifikasi Kesiswaan</x-sth.chip>
                            @endif
                            @if ($competition->weight_category)
                                {{-- "… B · Pemerintah (non-Puspresnas)" is wider than a 390px phone: the tag takes two lines there (FS1 QA). --}}
                                <x-sth.chip tone="gray" class="chip-wrap">Kategori penyelenggara {{ $competition->weight_category->value }}@if ($competition->event_status) · {{ $competition->event_status->label() }}@endif</x-sth.chip>
                            @endif
                        </div>
                        @if ($rumus)
                            <div class="flex flex-col gap-2.5 border-t border-line pt-4">
                                <p class="m-0 text-sm font-bold">Perkiraan poin jika Juara I @if ($teamLomba)<span class="font-medium text-muted">(tiap anggota tim dapat poin penuh)</span>@endif</p>
                                <x-sth.rumus-poin :tingkat="$rumus['tingkat']" :capaian="$rumus['capaian']" :penyelenggara="$rumus['penyelenggara']" :total="$rumus['total']" label="Perkiraan poin jika Juara I" />
                            </div>
                        @endif
                    </div>
                </x-sth.tab-panel>

                <x-sth.tab-panel tabs="info-lomba" name="persyaratan">
                    <ul class="m-0 flex list-none flex-col gap-3 p-0">
                        @foreach ($syarat as $item)
                            <li class="flex items-start gap-3">
                                <span class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-green-bg text-green" aria-hidden="true"><x-sth.icon name="check" :size="16" /></span>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-sth.tab-panel>

                <x-sth.tab-panel tabs="info-lomba" name="jadwal">
                    @if ($jadwal === [])
                        <p class="m-0 text-muted">Jadwal belum diisi Kesiswaan.</p>
                    @else
                        {{-- D-147: Pendaftaran, Pelaksanaan, Laporan hasil (no report deadline, D-151). --}}
                        <dl class="m-0 flex flex-col p-0">
                            @foreach ($jadwal as $row)
                                <div class="grid grid-cols-1 gap-x-4 gap-y-1 border-b border-line py-3 last:border-b-0 sm:grid-cols-[130px_minmax(0,1fr)]">
                                    <dt class="font-semibold">{{ $row['label'] }}</dt>
                                    <dd class="num m-0">{{ $row['value'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </x-sth.tab-panel>

                <x-sth.tab-panel tabs="info-lomba" name="dokumen">
                    <ul class="m-0 flex list-none flex-col gap-3 p-0">
                        @if ($competition->juknisFile)
                            <li class="flex items-center gap-3 rounded-tile border border-line p-3">
                                <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-control bg-red-bg text-red" aria-hidden="true"><x-sth.icon name="file" /></span>
                                <div class="flex min-w-0 flex-1 flex-col">
                                    <span class="ellip font-bold">{{ $competition->juknisFile->original_name }}</span>
                                    <span class="num text-xs text-muted">{{ $competition->juknisFile->isImage() ? 'Gambar' : 'PDF' }} · {{ \App\Livewire\Siswa\Lomba\LombaPresenter::fileSize($competition->juknisFile->size) }}</span>
                                </div>
                                <x-sth.button variant="quiet" size="sm" icon="download" :href="route('files.show', $competition->juknisFile)">Download</x-sth.button>
                            </li>
                        @endif
                        @foreach ($links as $link)
                            <li class="flex items-center gap-3 rounded-tile border border-line p-3">
                                <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-control bg-primary-tint text-primary" aria-hidden="true"><x-sth.icon name="link" /></span>
                                <div class="flex min-w-0 flex-1 flex-col">
                                    <span class="font-bold">{{ $link['label'] }}</span>
                                    <span class="ellip text-xs text-muted">Link ke situs luar: {{ $link['host'] }}</span>
                                </div>
                                <a class="btn btn-quiet btn-sm" href="{{ $link['url'] }}" target="_blank" rel="nofollow noopener noreferrer ugc">Buka<span class="sr-only"> {{ $link['label'] }} ({{ $link['host'] }}, tab baru)</span></a>
                            </li>
                        @endforeach
                        @if (! $competition->juknisFile && $links === [])
                            <li class="text-muted">Belum ada dokumen. Tanyakan ke Kesiswaan.</li>
                        @endif
                    </ul>
                </x-sth.tab-panel>
            </x-sth.card>
        </div>

        <aside aria-labelledby="h-daftar" class="card flex flex-col gap-4 p-5 lg:sticky lg:top-6">
            <livewire:siswa.pendaftaran.form :competition-id="$competition->getKey()" />
        </aside>
    </div>
</div>
