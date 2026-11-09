{{--
    HTML preview of the guru letter (Surat Perintah Tugas). SV8, D-194: one partial per layout of the letter
    (LetterTemplate::GURU_LAYOUTS): a new letter and every letter printed since then show the school's one-page letter
    (`pratinjau-guru-langsung`); a letter printed before keeps the preview of what its PDF printed, the M1-A letter
    with its Lampiran (`pratinjau-guru-lampiran`, D-108: its stored template reads as GURU_LAYOUT_LAMPIRAN).
    Vars: $content (LetterContent) · $number (issued or typed number, or null) · $tpl / $letterTemplate (optional)
--}}
@php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current()) {{-- B5: TU letter template --}}
@if ($tpl->guruLayout === \App\Actions\Templates\LetterTemplate::GURU_LAYOUT_LAMPIRAN)
    @include('livewire.tata-usaha.partials.pratinjau-guru-lampiran')
@else
    @include('livewire.tata-usaha.partials.pratinjau-guru-langsung')
@endif
