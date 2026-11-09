{{--
    Surat Perintah Tugas for the guru pembimbing. SV8, D-194 (lead, 2026-11-09: "samakan persis seperti template pdf nya
    harus identik 100%"): the body is the partial of LetterTemplate::GURU_LAYOUT, today `guru-langsung` (the school's PDF
    of that day: one page, the guru right under "Kepada", no Lampiran). A letter printed before kept its PDF; only its
    TU preview still draws the M1-A layout with the Lampiran (LetterTemplate::GURU_LAYOUT_LAMPIRAN). Another version of
    the school = one more layout key and its partial here (measures in layout.blade.php).
    Vars: $content (App\Actions\Letters\LetterContent) · $number · $verification · $pdfImages · $replaces
          · $letterTemplate (optional, B5: the TU letter template)
--}}
@extends('pdf::layout', ['title' => 'Surat Perintah Tugas '.$number, 'letter' => 'guru'])

@section('content')
    {{-- B5: fixed texts from the TU letter template (App\Actions\Templates\LetterTemplate, plain text, escaped). --}}
    @php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
    @include('pdf::partials.guru-'.\App\Actions\Templates\LetterTemplate::GURU_LAYOUT)
@endsection
