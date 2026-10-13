{{-- Verifikasi hasil (board Kesiswaan-Verifikasi-Hasil). Data prepared in App\Livewire\Kesiswaan\VerifikasiHasil. --}}
<div class="grid grid-cols-1 items-start gap-[22px] lg:grid-cols-[minmax(300px,360px)_1fr]">
    <section aria-labelledby="h-antrean" class="flex flex-col gap-3">
        <div class="flex items-center justify-between gap-2">
            <h2 id="h-antrean" class="title-section m-0">Laporan masuk</h2>
            <x-sth.chip tone="blue" size="sm">{{ $queue->count() }} menunggu</x-sth.chip>
        </div>

        @if ($queue->isEmpty())
            <x-sth.empty-state icon="check-circle" title="Semua laporan sudah diperiksa">Laporan baru muncul di sini.</x-sth.empty-state>
        @else
            <ul class="m-0 flex list-none flex-col gap-2 p-0">
                @foreach ($queue as $item)
                    <li wire:key="q-{{ $item['id'] }}">
                        <button type="button" wire:click="select('{{ $item['id'] }}')" aria-pressed="{{ $item['selected'] ? 'true' : 'false' }}"
                            @class(['card flex w-full cursor-pointer items-start gap-3 p-3.5 text-left', 'border-primary ring-2 ring-primary-soft' => $item['selected']])>
                            @if ($item['bidang'])<x-sth.bidang-tile :bidang="$item['bidang']" size="sm" />@endif
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="clamp2 text-md leading-snug font-bold text-ink">{{ $item['name'] }}</span>
                                <span class="ellip text-xs text-muted">{{ $item['student'] }}</span>
                                <span class="text-xs text-muted">Klaim {{ $item['claim'] }} · {{ $item['when'] }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-label="Detail laporan" class="flex min-w-0 flex-col gap-4">
        @if ($decision)
            <x-sth.alert :tone="['green' => 'success', 'orange' => 'warning', 'red' => 'error'][$decision['tone']] ?? 'info'" :title="$decision['title']">{{ $decision['text'] }}</x-sth.alert>
        @endif

        @if ($detail === null)
            <x-sth.empty-state icon="shield" title="Pilih laporan">Pilih laporan di kiri untuk memeriksa bukti.</x-sth.empty-state>
        @else
            <x-sth.card class="flex flex-col gap-4" wire:key="detail-{{ $detail['id'] }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 flex-col gap-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($detail['bidang'])<x-sth.chip :bidang="$detail['bidang']" icon size="sm" />@endif
                            <x-sth.chip size="sm">{{ $detail['tingkat'] }}</x-sth.chip>
                            {{-- A penyelenggara name can be long: this tag may take two lines (QA7). --}}
                            <x-sth.chip size="sm" class="chip-wrap">{{ $detail['organizer'] ? $detail['organizer'].' · ' : '' }}kategori {{ $detail['kategori'] }}</x-sth.chip>
                        </div>
                        <h2 class="title-section m-0">{{ $detail['name'] }}</h2>
                    </div>
                    <x-sth.chip :tone="$detail['status'][0]">{{ $detail['status'][1] }}</x-sth.chip>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <x-sth.avatar-stack :names="array_column($detail['members'], 'name')" size="sm" />
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <span class="font-bold">
                            @foreach ($detail['members'] as $member){{ $member['name'] }}{{ $member['class'] ? ' · '.$member['class'] : '' }}@if (! $loop->last), @endif @endforeach
                        </span>
                        <span class="text-xs text-muted">
                            {{ $detail['team'] ? 'Tim '.count($detail['members']).' siswa' : 'Individu' }}{{ $detail['branch'] ? ' · '.$detail['branch'] : '' }}
                            · Dilaporkan {{ $detail['reportedAt'] }}{{ $detail['mentor'] ? ' · Pembimbing '.$detail['mentor'] : '' }}
                        </span>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <h3 class="label m-0">Bukti</h3>
                    @forelse ($detail['files'] as $file)
                        <div class="flex flex-wrap items-center gap-3 rounded-tile border border-line p-3">
                            @if ($file['thumb'])
                                <img src="{{ $file['thumb'] }}" alt="Preview {{ $file['kind'] }}" loading="lazy" class="size-16 rounded-control border border-line object-cover">
                            @else
                                <span class="inline-flex size-11 items-center justify-center rounded-tile bg-primary-tint text-primary"><x-sth.icon name="file" /></span>
                            @endif
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="ellip font-bold">{{ $file['name'] }}</span>
                                <span class="text-xs text-muted">{{ $file['kind'] }}{{ $file['size'] ? ' · '.$file['size'] : '' }}</span>
                            </span>
                            @if ($file['url'] && $file['preview'])
                                {{-- Preview popup (<x-sth.preview>): the href stays the download for a browser without JavaScript. --}}
                                <x-sth.button variant="quiet" size="sm" icon="eye" :href="$file['url']" aria-haspopup="dialog"
                                    :data-sth-preview="$file['preview']" :data-sth-preview-type="$file['previewType']"
                                    :data-sth-preview-name="$file['name']">Buka<span class="sr-only"> {{ $file['kind'] }} {{ $file['name'] }}</span></x-sth.button>
                            @elseif ($file['url'])
                                <x-sth.button variant="quiet" size="sm" icon="download" :href="$file['url']">Download<span class="sr-only"> {{ $file['kind'] }} {{ $file['name'] }}</span></x-sth.button>
                            @endif
                        </div>
                    @empty
                        <p class="m-0 text-sm text-muted">Tidak ada bukti terlampir.</p>
                    @endforelse
                </div>

                @if ($detail['researchTitle'])
                    <div class="flex flex-col gap-1 rounded-tile bg-subtle p-3.5">
                        <span class="label">Judul penelitian</span>
                        <span class="font-bold">{{ $detail['researchTitle'] }}</span>
                        @if ($detail['researchAbstract'])<p class="m-0 text-sm text-ink-2">{{ $detail['researchAbstract'] }}</p>@endif
                    </div>
                @endif

                @if ($detail['open'])
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="flex flex-col gap-0.5 rounded-tile border border-line p-3">
                            <span class="label">Klaim siswa</span>
                            <span class="font-bold">{{ $detail['claim'] }}</span>
                        </div>
                        <div class="flex flex-col gap-0.5 rounded-tile border border-line p-3">
                            <span class="label">Penetapan sekolah</span>
                            <span class="font-bold">{{ $detail['resultLabel'] }}</span>
                            @if ($detail['changed'])<span class="text-xs font-bold text-orange">Diubah dari klaim</span>@endif
                        </div>
                    </div>

                    <x-sth.radio-pick wire:model.live="finalResult" label="Capaian" :columns="5" :phone-columns="2" size="sm" :options="$resultOptions" />

                    <x-sth.card tone="gold" padding="sm" class="flex flex-col gap-2">
                        <span class="label">{{ $detail['pointsLabel'] }}</span>
                        @if ($detail['points'] !== null)
                            <x-sth.rumus-poin :tingkat="$detail['wLevel']" :capaian="$detail['wResult']" :penyelenggara="$detail['wOrganizer']" :total="$detail['points']" result-label="Poin" />
                        @else
                            <span class="text-sm text-ink-2">{{ $detail['catalogGap'] }}</span>
                        @endif
                    </x-sth.card>

                    <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                        <legend class="label mb-2">Pemeriksaan bukti · <span class="num">{{ $checkCount }}</span>/4</legend>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach ($checkLabels as $key => $label)
                                <x-sth.checkbox wire:model.live="checks.{{ $key }}" :id="'cek-'.$key">{{ $label }}</x-sth.checkbox>
                            @endforeach
                        </div>
                        @error('checks')<p class="field-error m-0"><x-sth.icon name="alert" :size="16" />{{ $message }}</p>@enderror
                    </fieldset>

                    <x-sth.textarea wire:model="note" label="Catatan untuk siswa" rows="3" hint="Wajib untuk minta revisi." />

                    @error('decision')<x-sth.alert tone="error">{{ $message }}</x-sth.alert>@enderror

                    @if ($detail['canDecide'])
                        <div class="flex flex-wrap items-center gap-2">
                            <x-sth.button icon="check" wire:click="verify" wire:loading.attr="disabled" :disabled="$checkCount < 4">Verifikasi dan beri poin</x-sth.button>
                            <x-sth.button variant="quiet" wire:click="requestRevision" wire:loading.attr="disabled">Minta revisi</x-sth.button>
                        </div>
                        <p class="m-0 text-xs text-muted">{{ $checkCount < 4 ? 'Lengkapi 4 pemeriksaan untuk memberi poin. ' : '' }}Prestasi terverifikasi otomatis masuk antrean Humas dan peringkat.</p>
                    @elseif ($detail['ownMentee'])
                        <x-sth.alert tone="warning">Laporan binaan sendiri diverifikasi petugas lain.</x-sth.alert>
                    @endif
                @else
                    <div class="flex flex-col gap-1 rounded-tile border border-line p-3">
                        <span class="label">Capaian akhir</span>
                        <span class="font-bold">{{ $detail['resultLabel'] }}</span>
                        @if ($detail['studentNote'])<span class="text-sm text-ink-2">Catatan: {{ $detail['studentNote'] }}</span>@endif
                    </div>
                @endif
            </x-sth.card>
        @endif
    </section>
</div>
