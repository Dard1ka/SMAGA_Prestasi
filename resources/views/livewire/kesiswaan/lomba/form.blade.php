{{-- Drawer form "Tambah / Ubah / Periksa lomba" (board Kesiswaan-Kelola-Lomba). Fields: the shared partial
     livewire/forms/competition-fields (also Siswa › Usulkan lomba). Rules live in the Competition Actions. --}}
<div>
    <x-sth.modal :id="\App\Livewire\Kesiswaan\Lomba\Form::DIALOG" :title="$title" variant="drawer"
        description="{{ $formMode === 'create' ? 'Lomba baru masuk antrean verifikasi dulu, termasuk buatan sendiri.' : ($submitterName ? 'Dikirim oleh '.$submitterName.'.' : null) }}">
        <form id="lomba-form-el" wire:submit="save" class="flex flex-col gap-5" novalidate>
            @if ($errors->has('decision') || $errors->has('status'))
                <x-sth.alert tone="error" data-sth-error-summary tabindex="-1">{{ $errors->first('decision') ?: $errors->first('status') }}</x-sth.alert>
            @endif

            {{-- "Ubah lomba" of a published lomba students are registered for: who a changed fact is told to
                 (UpdateCompetition, CompetitionChange). Not in "Tambah" or "Periksa lomba", not with nobody registered.
                 The two sentences follow CompetitionChange::statusesFor(): batas daftar and peserta are told only to
                 teams still collecting answers, never to the guru. --}}
            @if ($attachedStudents > 0)
                <x-sth.alert>{{ number_format($attachedStudents, 0, ',', '.') }} siswa sedang terdaftar di lomba ini. Perubahan tanggal, tempat, pelaksanaan, link, atau juknis diberitahukan ke siswa itu dan guru pembimbingnya. Perubahan batas daftar atau peserta hanya diberitahukan ke tim yang masih menunggu konfirmasi anggota.</x-sth.alert>
            @endif

            @include('livewire.forms.competition-fields', ['staff' => true])

            {{-- Q1-04: old lomba may be published for the record, but only with this tick (VerifyCompetition asks for it).
                 Right above "Simpan & publikasikan", the button it belongs to. --}}
            @if ($formMode === 'verify' && ($pastDates !== [] || $errors->has('confirmPast')))
                <div class="flex flex-col gap-1 rounded-tile border border-line bg-subtle p-3">
                    <x-sth.checkbox wire:model="confirmPast"
                        :hint="$pastDates !== [] ? 'Sudah lewat: '.implode(', ', $pastDates).'. Siswa tidak bisa mendaftar lagi lewat STH.' : null">
                        Tanggal lomba ini sudah lewat. Tetap publikasikan.
                    </x-sth.checkbox>
                </div>
            @endif
        </form>

        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($formMode === 'verify')
                <x-sth.button variant="soft" type="submit" form="lomba-form-el" wire:loading.attr="disabled">Simpan</x-sth.button>
                <x-sth.button icon="check" wire:click="saveAndPublish" wire:loading.attr="disabled">Simpan & publikasikan</x-sth.button>
            @else
                <x-sth.button type="submit" form="lomba-form-el" wire:loading.attr="disabled">
                    {{ $formMode === 'create' ? 'Simpan ke antrean' : 'Simpan perubahan' }}
                </x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
