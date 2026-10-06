{{--
    Demo surat perintah tugas guru (LOCAL demo data only; rendered by Database\Seeders\Support\Demo\DemoPdfRenderer):
    the school's real template resources/pdf/surat-perintah-tugas-guru.blade.php, unchanged, plus the CONTOH line at
    the foot of every page (inside the bottom margin of 15.3 pt).
    Vars: those of the real template ($content, $number, $verification, $replaces, $letterTemplate, $pdfImages) · $contoh
--}}
@extends('pdf::surat-perintah-tugas-guru')

@section('content')
    <div style="position: fixed; left: 0; right: 0; bottom: -11.5pt; text-align: center; font-family: Helvetica, Arial, sans-serif; font-size: 6.5pt; line-height: 7pt; color: #444;">{{ $contoh }}</div>
    @parent
@endsection
