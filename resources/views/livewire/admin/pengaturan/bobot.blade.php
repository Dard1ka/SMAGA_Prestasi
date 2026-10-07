{{-- Tab "Bobot poin" (Admin-Pengaturan.dc.html). Display only: points are computed by App\Domain\Points. --}}
<section aria-labelledby="h-bobot" class="flex flex-col gap-5">
    <x-sth.page-header id="h-bobot" title="Bobot poin" subtitle="Poin = Tingkat × Capaian × Penyelenggara." />

    @error('weights')
        <x-sth.alert tone="error">{{ $message }}</x-sth.alert>
    @enderror

    <form id="bobot-form" wire:submit="save" class="flex flex-col gap-5" novalidate>
        <div class="grid gap-4 xl:grid-cols-3">
            @foreach ($rows as $group => $section)
                <x-sth.card as="section" padding="none" class="min-w-0 overflow-hidden" aria-labelledby="h-bobot-{{ $group }}">
                    <h3 id="h-bobot-{{ $group }}" class="title-card px-5 pt-4 pb-3">{{ $section['title'] }}</h3>
                    <table class="tbl">
                        <caption class="sr-only">Bobot {{ mb_strtolower($section['title']) }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ $section['column'] }}</th>
                                <th scope="col" class="w-28 text-right">Bobot</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($section['items'] as $key => $item)
                                <tr wire:key="w-{{ $group }}-{{ $key }}">
                                    <th scope="row" class="h-auto bg-transparent py-2.5 text-sm font-semibold whitespace-normal text-ink">
                                        {{ $item['label'] }}
                                        @if ($item['hint'] !== null)
                                            <span class="block text-xs font-medium text-muted">{{ $item['hint'] }}</span>
                                        @endif
                                    </th>
                                    <td class="text-right">
                                        <x-sth.input
                                            wire:model.live.debounce.400ms="weights.{{ $group }}.{{ $key }}"
                                            id="w-{{ $group }}-{{ $key }}"
                                            :label="$section['title'].' '.$item['label']"
                                            hide-label
                                            inputmode="decimal"
                                            autocomplete="off"
                                            class="ml-auto w-24"
                                        />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-sth.card>
            @endforeach
        </div>

        <x-sth.card tone="gold" as="section" aria-labelledby="h-bobot-contoh" class="flex flex-col gap-3">
            <h3 id="h-bobot-contoh" class="title-card">Contoh langsung · Nasional · Juara II · Kategori A</h3>
            <x-sth.rumus-poin :tingkat="$example['level']" :capaian="$example['result']" :penyelenggara="$example['organizer']" :total="$example['total']" />
        </x-sth.card>

        <x-sth.card as="section" class="flex flex-col gap-2">
            <p class="flex items-center gap-2 font-semibold"><x-sth.icon name="users" />Anggota tim mendapat poin penuh</p>
            <p class="muted text-sm">Setiap anggota menerima poin yang sama dengan ketua. Perubahan bobot menghitung ulang poin semua prestasi yang sudah ada.</p>
        </x-sth.card>

        <x-sth.input wire:model="note" label="Catatan perubahan" hint="Contoh: rapat kurikulum 12 Okt 2026." optional maxlength="255" />

        <div class="flex flex-wrap items-center justify-end gap-3">
            <x-sth.button variant="quiet" wire:click="resetForm">Batalkan perubahan</x-sth.button>
            <x-sth.button data-sth-open="bobot-konfirmasi" aria-haspopup="dialog">Simpan bobot</x-sth.button>
        </div>
    </form>

    <x-sth.modal id="bobot-konfirmasi" title="Simpan bobot baru?" description="Poin semua prestasi akan dihitung ulang dengan bobot ini. Perubahan tercatat di log audit." size="sm">
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="bobot-form" wire:loading.attr="disabled" wire:target="save">Simpan bobot</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    @if ($versions->isNotEmpty())
        <x-sth.table caption="Riwayat versi bobot, 5 terbaru" caption-visible>
            <x-slot:head>
                <th scope="col">Berlaku sejak</th>
                <th scope="col">Oleh</th>
                <th scope="col">Catatan</th>
                <th scope="col" class="relative w-28"><span class="sr-only">Status</span></th>
            </x-slot:head>
            @foreach ($versions as $version)
                <tr wire:key="v-{{ $version->id }}">
                    <td class="num">{{ $version->effective_at->translatedFormat('j M Y H:i') }}</td>
                    <td>{{ $version->creator?->name ?? 'Sistem' }}</td>
                    <td class="muted">{{ $version->note ?? '–' }}</td>
                    <td>@if ($version->id === $activeId)<x-sth.chip tone="green" size="sm">Aktif</x-sth.chip>@endif</td>
                </tr>
            @endforeach
        </x-sth.table>
    @endif
</section>
