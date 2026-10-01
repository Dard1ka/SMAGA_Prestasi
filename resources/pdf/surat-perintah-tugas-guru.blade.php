{{--
    Surat Perintah Tugas for the guru pembimbing (school Word template, mockup-v2-notes/F.md) + Lampiran page.
    Vars: $content (App\Actions\Letters\LetterContent) · $number · $verification · $pdfImages
--}}
@extends('pdf::layout', ['title' => 'Surat Perintah Tugas '.$number])

@section('content')
    @include('pdf::partials.kop')

    <p class="judul">SURAT PERINTAH TUGAS</p>
    <p class="nomor">Nomor {{ $number }}</p>

    <table class="kv">
        <tr>
            <td class="k">Dasar</td>
            <td class="s">:</td>
            <td>
                <table class="ol">
                    <tr><td class="n">1.</td><td>Program kerja {{ \App\Actions\Letters\LetterContent::SCHOOL }} tahun pelajaran {{ $content->schoolYear }}.</td></tr>
                    <tr><td class="n">2.</td><td>{{ $content->guruBasis() }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <p class="center bold mt">MEMERINTAHKAN:</p>

    <table class="kv">
        <tr>
            <td class="k">Kepada</td>
            <td class="s">:</td>
            <td>Nama/Pangkat/Gol/NIP/Jabatan : Terlampir</td>
        </tr>
        <tr>
            <td class="k">Untuk</td>
            <td class="s">:</td>
            <td>
                <table class="ol">
                    <tr><td class="n">1.</td><td class="justify">{{ $content->guruTask() }}</td></tr>
                    <tr><td class="n">2.</td><td>Melaporkan hasil pelaksanaan tugas kepada pejabat pemberi tugas.</td></tr>
                    <tr><td class="n">3.</td><td>Perintah ini dilaksanakan dengan penuh tanggung jawab.</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @include('pdf::partials.ttd')

    <div class="page-break">
        <p class="bold">Lampiran</p>
        <p>Surat Perintah Tugas Nomor {{ $number }}<br>Tanggal {{ $content->issuedOnText }}</p>

        <table class="grid">
            <thead>
                <tr>
                    <th class="w-no">No</th>
                    <th>Nama</th>
                    <th class="w-pangkat">Pangkat/Gol</th>
                    <th class="w-nip">NIP</th>
                    <th class="w-jabatan">Jabatan</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="c">1</td>
                    <td>{{ $content->mentor['name'] ?? '-' }}</td>
                    <td class="c">{{ $content->mentor['rank'] ?? '-' }}</td>
                    <td class="c">{{ $content->mentor['nip'] ?? '-' }}</td>
                    <td class="c">{{ $content->mentor['position'] ?? 'Guru' }}</td>
                </tr>
            </tbody>
        </table>

        @include('pdf::partials.ttd')
    </div>
@endsection
