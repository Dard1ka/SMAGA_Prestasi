{{--
    Surat Perintah Tugas for the guru pembimbing + Lampiran page, laid out like the school's Word file
    "ST PENGUKUHAN KEG OPSI" (M1-A; measures in layout.blade.php). Page 1: kop · title · Nomor · "Dasar" (the
    regulation texts of the TU letter template, then the invitation) · MEMERINTAHKAN: · "Kepada" (1. Nama /
    Pangkat/Gol / NIP / Jabatan, each with its colon, a brace and "Terlampir") · "Untuk" (three points) ·
    signature. Page 2: Lampiran heading, Nomor, Tanggal, "DAFTAR PENDAMPING …" / "TAHUN …", the guru's data
    (no borders, as in the school's file), signature of the Kepala Sekolah.
    Vars: $content (App\Actions\Letters\LetterContent) · $number · $verification · $pdfImages · $replaces
--}}
@extends('pdf::layout', ['title' => 'Surat Perintah Tugas '.$number, 'letter' => 'guru'])

@section('content')
    {{-- B5: fixed texts from the TU letter template (App\Actions\Templates\LetterTemplate, plain text, escaped). --}}
    @php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
    @php($basisItems = array_merge($tpl->guruBasisItems($content->schoolYear), [$content->guruBasis()]))
    @php($attachmentTitle = $content->attachmentTitle())
    @include('pdf::partials.kop', ['kop' => 'guru'])
    <div class="g-jarak-kop"></div>

    <div class="g-judul">SURAT PERINTAH TUGAS</div>
    <div class="g-judul">Nomor {{ $number }}</div>
    <div class="g-baris"></div>

    <table class="g-blok">
        <tr>
            <td class="k-dasar g-11">Dasar</td>
            <td class="s g-11">:</td>
            <td class="isi">
                <table class="g-daftar g-11">
                    @foreach ($basisItems as $item)
                        <tr><td class="n">{{ $loop->iteration }}.</td><td class="rata">{{ $item }}</td></tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
    <div class="g-baris-kecil"></div>

    <div class="g-perintah">MEMERINTAHKAN:</div>

    {{-- One table, four rows, so each label keeps its own colon; the brace spans them and points at "Terlampir". --}}
    <table class="g-kepada">
        <tr>
            <td class="k">Kepada</td>
            <td class="s">:</td>
            <td class="n">1.</td>
            <td class="label">Nama</td>
            <td class="titik">:</td>
            <td class="kurung" rowspan="4"><img src="{{ resource_path('pdf/assets/kurung-kurawal.svg') }}" alt=""></td>
            <td class="lampir"></td>
        </tr>
        <tr>
            <td class="k"></td><td class="s"></td><td class="n"></td>
            <td class="label">Pangkat/Gol</td>
            <td class="titik">:</td>
            <td class="lampir">Terlampir</td>
        </tr>
        <tr>
            <td class="k"></td><td class="s"></td><td class="n"></td>
            <td class="label">NIP</td>
            <td class="titik">:</td>
            <td class="lampir"></td>
        </tr>
        <tr>
            <td class="k"></td><td class="s"></td><td class="n"></td>
            <td class="label">Jabatan</td>
            <td class="titik">:</td>
            <td class="lampir"></td>
        </tr>
    </table>
    <div class="g-kepada-akhir"></div>

    {{-- "Untuk" and the signature never part: with a long lomba name, penyelenggara and place the signature alone
         went to page 2 (QA8). If they do not fit, both start the next page. --}}
    <div class="utuh">
    <table class="g-blok">
        <tr>
            <td class="k-untuk">Untuk</td>
            <td class="s">:</td>
            <td class="isi">
                <table class="g-daftar">
                    <tr><td class="n">1.</td><td class="rata">{{ $content->guruTask() }}</td></tr>
                    <tr><td class="n">2.</td><td class="rata">{{ $tpl->guruReport() }}</td></tr>
                    <tr><td class="n">3.</td><td class="rata">{{ $tpl->guruClosing() }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
    <div class="g-baris"></div>

    @include('pdf::partials.ttd', [
        'ttd' => 'g-ttd',
        'ttdLines' => [$tpl->city().', '.$content->issuedOnText, $tpl->signerTitle()],
    ])
    </div>

    <div class="halaman-baru">
        <div class="g-lampiran-atas"></div>
        <div class="g-lampiran">Lampiran Surat Tugas Kepala {{ $tpl->school() }}</div>
        <table class="g-lampiran g-lampiran-kepala">
            <tr><td class="k">Nomor</td><td>: {{ $number }}</td></tr>
            <tr><td class="k">Tanggal</td><td>: {{ $content->issuedOnText }}</td></tr>
        </table>
        <div class="g-baris"></div>
        <div class="g-baris"></div>

        <div class="g-lampiran g-lampiran-judul">{{ $attachmentTitle[0] }}</div>
        <div class="g-lampiran g-lampiran-judul">{{ $attachmentTitle[1] }}</div>
        <div class="g-baris"></div>

        <table class="g-pendamping">
            <tr><td class="no">1</td><td class="label">Nama</td><td class="nilai">: {{ $content->mentor['name'] ?? '-' }}</td></tr>
            <tr><td class="no"></td><td class="label">Pangkat/Gol</td><td class="nilai">: {{ $content->mentor['rank'] ?? '-' }}</td></tr>
            <tr><td class="no"></td><td class="label">NIP</td><td class="nilai">: {{ $content->mentor['nip'] ?? '-' }}</td></tr>
            <tr><td class="no"></td><td class="label">Jabatan</td><td class="nilai">: {{ $content->mentor['position'] ?? 'Guru' }}</td></tr>
        </table>
        <div class="g-baris"></div>
        <div class="g-baris"></div>
        <div class="g-baris"></div>

        @include('pdf::partials.ttd', [
            'ttd' => 'g-ttd g-ttd-lampiran',
            'ttdLines' => ['Kepala '.$tpl->school()],
        ])
    </div>
@endsection
