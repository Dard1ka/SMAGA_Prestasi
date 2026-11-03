{{-- Siswa · Usulkan lomba (D-145). Step 1: the whole lomba (shared partial livewire/forms/competition-fields). Step 2: the
     registration panel of the lomba just saved. State and rules: App\Livewire\Siswa\Lomba\Usulkan + CompetitionFields. --}}
<div class="flex flex-col gap-[22px]">
    @if ($competitionId === null)
        <x-sth.card class="flex flex-col gap-5">
            <div class="flex flex-col gap-1">
                <h2 class="title-section m-0">Data lomba</h2>
                <p class="m-0 text-sm text-muted">Isi sesuai juknis. Setelah disimpan, kamu bisa langsung lanjut ke pendaftaran lomba ini.</p>
            </div>

            <form id="usulkan" wire:submit="simpan" class="flex flex-col gap-6" novalidate>
                @include('livewire.forms.competition-fields', ['staff' => false])

                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
                    <x-sth.button variant="quiet" :href="route('siswa.lomba.index')">Batal</x-sth.button>
                    <x-sth.button type="submit" icon="check" wire:loading.attr="disabled" wire:target="simpan,form.juknis,form.cover">Simpan</x-sth.button>
                </div>
            </form>
        </x-sth.card>
    @else
        <x-sth.alert tone="success" title="Usulan lomba tersimpan">
            Kesiswaan memeriksa usulan ini sebelum lomba tampil di katalog. Pantau statusnya di Cari lomba › Usulan saya.
        </x-sth.alert>

        <div class="grid grid-cols-1 items-start gap-[22px] lg:grid-cols-[minmax(0,1fr)_minmax(0,420px)]">
            <aside aria-labelledby="h-daftar" class="card flex flex-col gap-4 p-5">
                <livewire:siswa.pendaftaran.form :competition-id="$competitionId" :key="'daftar-'.$competitionId" />
            </aside>
            {{-- Once the registration is sent, "Nanti saja" no longer fits; the lomba stays one link away (FS1 QA). --}}
            <div class="flex flex-col items-start gap-2">
                @if ($registered)
                    <p class="m-0 text-sm text-muted">Lomba ini juga bisa dibuka dari Detail lomba.</p>
                    <a class="lnk" href="{{ route('siswa.lomba.show', $competitionId) }}">Buka Detail lomba</a>
                @else
                    <p class="m-0 text-sm text-muted">Belum mau mendaftar sekarang? Lomba ini tetap bisa dibuka dari Detail lomba.</p>
                    <a class="lnk" href="{{ route('siswa.lomba.show', $competitionId) }}">Nanti saja</a>
                @endif
            </div>
        </div>
    @endif
</div>
