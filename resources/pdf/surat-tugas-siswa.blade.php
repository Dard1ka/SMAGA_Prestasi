{{--
    Surat tugas siswa (school Excel template, mockup-v2-notes/F.md): one letter for the whole team.
    Vars: $content (App\Actions\Letters\LetterContent) · $number · $verification · $pdfImages
--}}
@extends('pdf::layout', ['title' => 'Surat Tugas '.$number])

@section('content')
    {{-- B5: fixed texts from the TU letter template (App\Actions\Templates\LetterTemplate, plain text, escaped). --}}
    @php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
    @include('pdf::partials.kop')

    <p class="judul"><span class="judul-spasi">SURAT TUGAS</span></p>
    <p class="nomor">Nomor : {{ $number }}</p>

    <table class="kv">
        <tr>
            <td class="k">Dasar</td>
            <td class="s">:</td>
            <td>{{ $tpl->siswaBasis($content->organizer) }}</td>
        </tr>
        @if ($content->basisNumber !== null)
            <tr>
                <td class="k">Nomor</td>
                <td class="s">:</td>
                <td>{{ $content->basisNumber }}</td>
            </tr>
        @endif
        @if ($content->basisDateText !== null)
            <tr>
                <td class="k">Tanggal</td>
                <td class="s">:</td>
                <td>{{ $content->basisDateText }}</td>
            </tr>
        @endif
    </table>

    <p class="mt">{{ $tpl->siswaIntro() }}</p>

    <table class="grid">
        <thead>
            <tr>
                <th class="w-no">NO</th>
                <th>NAMA MURID</th>
                <th class="w-nis">NIS</th>
                <th class="w-kelas">KELAS</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($content->students as $student)
                <tr>
                    <td class="c">{{ $student['no'] }}</td>
                    <td>{{ $student['name'] }}</td>
                    <td class="c">{{ $student['nis'] }}</td>
                    <td class="c">{{ $student['class'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p>untuk mengikuti Lomba {{ $content->competitionName }}, pada :</p>

    <table class="kv">
        <tr>
            <td class="k">Hari / Tanggal</td>
            <td class="s">:</td>
            <td>{{ $content->dayAndDate() }}</td>
        </tr>
        <tr>
            <td class="k">Pukul</td>
            <td class="s">:</td>
            <td>{{ $content->scheduleTime }}</td>
        </tr>
        <tr>
            <td class="k">Tempat</td>
            <td class="s">:</td>
            <td>{{ $content->place }}</td>
        </tr>
    </table>

    <p class="justify mt">{{ $tpl->siswaClosing() }}</p>

    @include('pdf::partials.ttd')
@endsection
