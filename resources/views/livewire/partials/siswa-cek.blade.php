{{--
    SV6, D-185: the tick of one student row (SelectsListedStudents::$selectedStudents), the same on Admin › Kelas › Lihat
    siswa and both lists of Status aktivasi. $cekId is unique on the page (one prefix per list).
    @include('livewire.partials.siswa-cek', ['cekId' => 'kelas-siswa-pilih-'.$student['id'], 'userId' => $student['id'], 'name' => $student['name']])
--}}
<label class="inline-flex size-11 cursor-pointer items-center justify-center" for="{{ $cekId }}">
    <input type="checkbox" class="cek" id="{{ $cekId }}" value="{{ $userId }}" wire:model.live="selectedStudents">
    <span class="sr-only">Pilih {{ $name }}</span>
</label>
