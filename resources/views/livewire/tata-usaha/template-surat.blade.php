{{-- Tata Usaha · Template surat (UI-MAP §4c, backlog #7, B5). Plain-text fields only; the preview uses the TU preview partials. --}}
<div class="grid items-start gap-[22px] xl:grid-cols-[minmax(0,1fr)_minmax(0,560px)]">
    <div class="flex min-w-0 flex-col gap-[22px]">
        <x-sth.card as="section" aria-labelledby="h-template-versi" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-col gap-0.5">
                    <h2 id="h-template-versi" class="title-card">
                        {{ $current->version > 0 ? 'Versi '.$current->version : 'Teks bawaan' }}
                    </h2>
                    <p class="muted m-0 text-[13.5px]">
                        @if ($current->version > 0 && $current->updatedAt)
                            Diubah {{ $current->updatedByName ?? '-' }}, {{ \Carbon\CarbonImmutable::parse($current->updatedAt)->timezone(config('app.timezone'))->translatedFormat('j M Y H:i') }}.
                        @else
                            Belum pernah diubah.
                        @endif
                        Surat yang sudah terbit tidak berubah.
                    </p>
                </div>
                @if ($dirty)
                    <x-sth.chip tone="orange" icon="edit">Belum disimpan</x-sth.chip>
                @endif
            </div>
            <p class="m-0 text-sm muted">
                Kode yang bisa dipakai:
                @foreach ($placeholders as $code => $meaning)<code class="rounded bg-subtle px-1">{{ $code }}</code> {{ $meaning }}{{ $loop->last ? '.' : ', ' }}@endforeach
                Teks lain tertulis apa adanya.
            </p>
        </x-sth.card>

        <form wire:submit="save" class="flex flex-col gap-[22px]" novalidate>
            @foreach ($groups as $title => $keys)
                <x-sth.card as="section" aria-labelledby="h-tpl-{{ $loop->index }}" class="flex min-w-0 flex-col gap-4">
                    <h3 id="h-tpl-{{ $loop->index }}" class="title-card">{{ $title }}</h3>
                    @foreach ($keys as $key)
                        @php [$label, $max, $required, $allowed, $default, $hint] = $fields[$key]; @endphp
                        @if ($max > 150)
                            <x-sth.textarea wire:model.live.debounce.500ms="form.{{ $key }}" :label="$label" rows="3" :hint="$hint !== '' ? $hint : 'Maks. '.$max.' karakter.'" :optional="! $required" :disabled="! $canEdit" />
                        @else
                            <x-sth.input wire:model.live.debounce.500ms="form.{{ $key }}" :label="$label" :hint="$hint !== '' ? $hint : null" :optional="! $required" maxlength="{{ $max }}" autocomplete="off" :disabled="! $canEdit" />
                        @endif
                    @endforeach
                </x-sth.card>
            @endforeach

            <x-sth.card as="section" aria-labelledby="h-template-nomor" class="flex flex-col gap-2">
                <h3 id="h-template-nomor" class="title-card">Format nomor surat</h3>
                @foreach ($formats as $label => $format)
                    <p class="m-0 text-sm"><span class="font-semibold">{{ $label }}:</span> <code class="num">{{ $format }}</code></p>
                @endforeach
                <p class="m-0 text-sm muted">Diubah Admin di Pengaturan › Surat & tanda tangan.</p>
            </x-sth.card>

            @if ($canEdit)
                <div class="flex flex-wrap justify-end gap-2">
                    <x-sth.button variant="quiet" wire:click="restoreDefaults">Kembalikan bawaan</x-sth.button>
                    @if ($dirty)
                        <x-sth.button variant="quiet" wire:click="revert">Batalkan perubahan</x-sth.button>
                    @endif
                    <x-sth.button type="submit" wire:loading.attr="disabled" wire:target="save">Simpan template</x-sth.button>
                </div>
            @endif
        </form>

        <x-sth.card as="section" aria-labelledby="h-template-riwayat" class="flex flex-col gap-3">
            <h2 id="h-template-riwayat" class="title-card">Riwayat versi</h2>
            @forelse ($history as $item)
                <div wire:key="versi-{{ $item['id'] }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line-soft pb-2.5 last:border-0">
                    <x-sth.chip tone="blue" size="sm">Versi {{ $item['version'] }}</x-sth.chip>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="text-sm font-semibold">{{ $item['by'] }} · {{ $item['at'] }}</span>
                        <span class="muted text-[12.5px]">Diubah: {{ $item['changed'] !== '' ? $item['changed'] : '-' }}</span>
                    </span>
                    @if ($canEdit && $item['restorable'])
                        <x-sth.button variant="quiet" size="sm" wire:click="useVersion({{ $item['id'] }})">Pakai lagi<span class="sr-only"> versi {{ $item['version'] }}</span></x-sth.button>
                    @endif
                </div>
            @empty
                <p class="muted m-0 text-sm">Belum ada versi tersimpan. Surat memakai teks bawaan.</p>
            @endforelse
        </x-sth.card>
    </div>

    <section aria-labelledby="h-template-pratinjau" class="flex min-w-0 flex-col gap-3 xl:sticky xl:top-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="h-template-pratinjau" class="title-card">Pratinjau</h2>
            <div class="seg" role="group" aria-label="Pilih surat">
                <button type="button" class="seg-btn" wire:click="showPreview('siswa')" aria-pressed="{{ $preview === 'siswa' ? 'true' : 'false' }}">Surat siswa</button>
                <button type="button" class="seg-btn" wire:click="showPreview('guru')" aria-pressed="{{ $preview === 'guru' ? 'true' : 'false' }}">Surat guru</button>
            </div>
        </div>
        <p class="muted m-0 text-[13px]">Contoh data fiktif. Tanda tangan asli tidak ditampilkan.</p>
        <div wire:loading.class="opacity-70">
            @if ($preview === 'guru')
                @include('livewire.tata-usaha.partials.pratinjau-guru')
            @else
                @include('livewire.tata-usaha.partials.pratinjau-siswa')
            @endif
        </div>
    </section>
</div>
