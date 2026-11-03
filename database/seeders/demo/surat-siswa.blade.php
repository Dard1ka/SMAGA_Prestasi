{{--
    Demo surat tugas siswa (LOCAL demo data only; rendered by Database\Seeders\Support\Demo\DemoPdfRenderer): the
    school's real template resources/pdf/surat-tugas-siswa.blade.php, unchanged, plus the CONTOH line at the foot of
    every page (below the verification block, inside the bottom margin of 56.7 pt).
    Vars: those of the real template ($content, $number, $verification, $replaces, $letterTemplate, $pdfImages) · $contoh
--}}
@extends('pdf::surat-tugas-siswa')

@section('content')
    <div style="position: fixed; left: 0; right: 0; bottom: -52pt; text-align: center; font-family: Helvetica, Arial, sans-serif; font-size: 6.5pt; line-height: 7pt; color: #444;">{{ $contoh }}</div>
    @parent
@endsection
