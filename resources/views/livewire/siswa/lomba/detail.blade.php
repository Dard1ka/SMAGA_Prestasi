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

    @unless ($competition->isPublished())
        <x-sth.alert tone="warning" title="Belum tampil di katalog">
            Lomba ini masih {{ mb_strtolower($competition->status->label()) }}. Siswa lain belum bisa melihatnya.
        </x-sth.alert>
    @endunless

    <div class="grid grid-cols-1 items-start gap-[18px] lg:grid-cols-[minmax(0,1fr)_380px]">
        <div class="flex min-w-0 flex-col gap-[18px]">
            <x-sth.card as="section" padding="none" class="overflow-hidden" aria-labelledby="h-lomba">
                {{-- The one big cover of this page: the lomba's poster, else the deep template drawing of its bidang. --}}
                <x-sth.sampul :bidang="$bidangKey" :seed="$competition->getKey()" :src="$poster['src'] ?? null"
                              tone="pekat" alt="Poster {{ $competition->name }}" class="h-36 items-start gap-2 p-4 sm:h-40">
                    <x-sth.chip tone="glass">{{ \App\Livewire\Siswa\Lomba\LombaPresenter::tingkat($competition) }}</x-sth.chip>
                    <x-sth.chip :tone="$window['tone']">{{ $window['label'] }}</x-sth.chip>
                    @if ($poster)
                        {{-- The cover shows the poster small; this opens the whole file in the preview popup (href = download). --}}
                        <x-sth.button variant="white" size="sm" icon="image" :href="$poster['href']" :navigate="false" class="ml-auto self-end"
                                      :data-sth-preview="$poster['preview']" :data-sth-preview-type="$poster['previewType']"
                                      :data-sth-preview-name="$poster['name']" aria-haspopup="dialog">Lihat poster</x-sth.button>
                    @endif
                </x-sth.sampul>
                <div class="flex flex-col gap-3 p-5">
                    <h1 id="h-lomba" class="title-page m-0 text-2xl sm:text-3xl">{{ $competition->name }}</h1>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($competition->bidang)
                                <x-sth.chip :bidang="$competition->bidang" />
                            @endif
                            <span class="text-sm text-muted">{{ $penyelenggara }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($registrants !== [])
                                <x-sth.avatar-stack :names="$registrants" />
                                <span class="text-xs text-muted"><span class="num">{{ count($registrants) }}</span> siswa SMAGA sudah mendaftar</span>
                            @else
                                <span class="text-xs text-muted">Belum ada siswa SMAGA yang mendaftar</span>
                            @endif
                        </div>
                    </div>
                </div>
            </x-sth.card>

            <x-sth.facts :items="$facts" />

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
                                <x-sth.chip tone="gray">Kategori penyelenggara {{ $competition->weight_category->value }}@if ($competition->event_status) · {{ $competition->event_status->label() }}@endif</x-sth.chip>
                            @endif
                        </div>
                        @if ($rumus)
                            <div class="flex flex-col gap-2.5 border-t border-line pt-4">
                                <p class="m-0 text-sm font-bold">Perkiraan poin jika Juara I <span class="font-medium text-muted">(tiap anggota tim dapat poin penuh)</span></p>
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
                        <ol class="m-0 flex list-none flex-col p-0">
                            @foreach ($jadwal as $row)
                                <li class="grid grid-cols-[110px_minmax(0,1fr)] items-center gap-x-4 gap-y-1 border-b border-line py-3 last:border-b-0 sm:grid-cols-[130px_minmax(0,1fr)_auto]">
                                    <span class="display num text-md font-bold">{{ $row['date'] }}</span>
                                    <span class="font-semibold">{{ $row['label'] }}</span>
                                    @if ($row['chip'])
                                        <x-sth.chip :tone="$row['tone']" size="sm" class="col-start-2 justify-self-start sm:col-start-auto sm:justify-self-end">{{ $row['chip'] }}</x-sth.chip>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
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
