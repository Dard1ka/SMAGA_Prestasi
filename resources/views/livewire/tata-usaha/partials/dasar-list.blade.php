{{--
    SV8, D-195 / SV9, D-200: the Dasar list of one letter on TU › Template surat, the same editor for both letters: 1 to
    $dasarMax rows, "Tambah dasar", "Naikkan" / "Turunkan" and "Hapus" change the unsaved draft only.
    Vars: $group ('siswa' | 'guru') · $dasarFields (the shown fields) · $dasarMax · $fields · $canEdit
--}}
{{-- The method names are whole strings (ViewWiringTest skips a name drawn at render time; GuruDasarListTest and
     SiswaDasarListTest check the rendered wire:click of both lists). --}}
@php($method = $group === 'guru' ? 'Guru' : 'Siswa')
@php($add = 'add'.$method.'Dasar')
@php($remove = 'remove'.$method.'Dasar')
@php($moveUp = 'move'.$method.'DasarUp')
<fieldset class="m-0 flex min-w-0 flex-col gap-3 border-0 p-0">
    <legend class="mb-1 text-sm font-bold">Surat {{ $group }}: Dasar</legend>
    <p class="muted m-0 text-xs">
        @if ($group === 'guru')
            Tercetak bernomor 1., 2., dan seterusnya; surat undangan yang diisi saat membuat surat selalu jadi nomor terakhir.
            Paling banyak {{ $dasarMax }} dasar. Dasar yang kosong tidak disimpan.
        @else
            Satu dasar tercetak tanpa nomor. Lebih dari satu tercetak bernomor 1., 2., dan seterusnya; surat undangan yang diisi saat membuat surat selalu jadi nomor terakhir.
            Paling banyak {{ $dasarMax }} dasar. Dasar yang kosong tidak disimpan.
        @endif
    </p>
    @foreach ($dasarFields as $dasarKey)
        <div wire:key="{{ $group }}-dasar-{{ $loop->index }}" class="flex min-w-0 flex-col gap-2 rounded-tile border border-line p-3">
            <x-sth.textarea wire:model.live.debounce.500ms="form.{{ $dasarKey }}" :label="$fields[$dasarKey][0]" rows="3"
                            :hint="$fields[$dasarKey][5].' Maks. '.$fields[$dasarKey][1].' karakter.'" :optional="! $fields[$dasarKey][2]" :disabled="! $canEdit" />
            @if ($canEdit && count($dasarFields) > 1)
                <div class="flex flex-wrap justify-end gap-2">
                    @unless ($loop->first)
                        <x-sth.button variant="quiet" size="sm" wire:click="{{ $moveUp }}({{ $loop->index }})">Naikkan<span class="sr-only"> dasar nomor {{ $loop->iteration }}</span></x-sth.button>
                    @endunless
                    @unless ($loop->last)
                        <x-sth.button variant="quiet" size="sm" wire:click="{{ $moveUp }}({{ $loop->index + 1 }})">Turunkan<span class="sr-only"> dasar nomor {{ $loop->iteration }}</span></x-sth.button>
                    @endunless
                    <x-sth.button variant="quiet" size="sm" icon="trash" wire:click="{{ $remove }}({{ $loop->index }})">Hapus<span class="sr-only"> dasar nomor {{ $loop->iteration }}</span></x-sth.button>
                </div>
            @endif
        </div>
    @endforeach
    @if ($canEdit && count($dasarFields) < $dasarMax)
        <x-sth.button variant="soft" size="sm" icon="plus" class="self-start" wire:click="{{ $add }}">Tambah dasar</x-sth.button>
    @endif
</fieldset>
