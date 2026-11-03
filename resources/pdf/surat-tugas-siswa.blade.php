{{--
    Surat tugas siswa: one letter for the whole team, laid out like the school's Excel sheet "ST_LOMBA … TIM"
    (M1-A; measures in layout.blade.php). Rows of the sheet, top to bottom: kop · title · Nomor · Dasar ·
    "Kepala … memberikan tugas kepada :" · table NO | NAMA MURID | NIS | KELAS · "untuk mengikuti Lomba …, pada :" ·
    Hari / Tanggal / Pukul / Tempat · closing · signature block centred on the right.
    D-154 (the school's printed letter of 2026-10-16): "Dasar" is one regulation text of the template, wrapping under
    itself, close under the Nomor and two empty rows above the intro; the invitation's Nomor / Tanggal rows are gone.
    D-157: an invitation TU typed (optional on this letter) is "Dasar" number 2, one sentence without "Hal".
    Vars: $content (App\Actions\Letters\LetterContent) · $number · $verification · $pdfImages · $replaces
--}}
@extends('pdf::layout', ['title' => 'Surat Tugas '.$number, 'letter' => 'siswa'])

@section('content')
    {{-- B5: fixed texts from the TU letter template (App\Actions\Templates\LetterTemplate, plain text, escaped). --}}
    @php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
    @php([$dateStart, $dateEnd] = $content->dateRange())
    @php([$timeStart, $timeEnd] = $content->timeRange())
    @include('pdf::partials.kop', ['kop' => 'siswa'])
    <div class="s-jarak-kop"></div>

    <div class="s-judul tebal garis-bawah">S U R A T &nbsp; T U G A S</div>
    <div class="s-judul">Nomor : {{ $number }}</div>
    <div class="s-jarak-dasar"></div>

    {{-- D-157: with an invitation typed by TU, "Dasar" is numbered: 1. the regulation text, 2. the invitation. --}}
    @php($siswaInvitation = $content->siswaInvitation())
    <table class="s-dasar">
        <tr>
            <td class="k">Dasar</td>
            <td class="s">:</td>
            @if ($siswaInvitation === null)
                <td>{{ $tpl->siswaDasar($content->schoolYear) }}</td>
            @else
                <td>
                    <table class="s-daftar">
                        <tr><td class="n">1.</td><td>{{ $tpl->siswaDasar($content->schoolYear) }}</td></tr>
                        <tr><td class="n">2.</td><td>{{ $siswaInvitation }}</td></tr>
                    </table>
                </td>
            @endif
        </tr>
    </table>
    <div class="s-baris"></div>
    <div class="s-baris"></div>

    <div class="s-lebar">{{ $tpl->siswaIntro() }}</div>
    <div class="s-baris"></div>

    <table class="s-murid">
        <thead>
            <tr>
                <th class="no">NO</th>
                <th>NAMA MURID</th>
                <th class="nis">NIS</th>
                <th class="kelas">KELAS</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($content->students as $student)
                <tr>
                    <td class="no">{{ $student['no'] }}</td>
                    <td class="nama">{{ $student['name'] }}</td>
                    <td class="nis">{{ $student['nis'] }}</td>
                    <td class="kelas">{{ $student['class'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="s-baris"></div>

    {{-- These two sentences have no width of their own: they run to the page margin, which is the last column (RV1). --}}
    <div>untuk mengikuti {{ $content->activity() }}, pada :</div>
    <div class="s-baris"></div>

    <table class="s-pelaksanaan">
        <tr>
            <td class="k">Hari</td>
            <td class="s">:</td>
            <td colspan="3">{{ $content->scheduleDay }}</td>
        </tr>
        <tr>
            <td class="k">Tanggal</td>
            <td class="s">:</td>
            @if ($dateEnd !== null)
                <td class="awal">{{ $dateStart }}</td>
                <td class="sd">s.d</td>
                <td>{{ $dateEnd }}</td>
            @else
                <td colspan="3">{{ $dateStart }}</td>
            @endif
        </tr>
        <tr>
            <td class="k">Pukul</td>
            <td class="s">:</td>
            @if ($timeEnd !== null)
                <td class="awal">{{ $timeStart }}</td>
                <td class="sd">s.d</td>
                <td>{{ $timeEnd }}</td>
            @else
                <td colspan="3">{{ $timeStart }}</td>
            @endif
        </tr>
        <tr>
            <td class="k">Tempat</td>
            <td class="s">:</td>
            <td colspan="3">{{ $content->place }}</td>
        </tr>
    </table>
    <div class="s-baris"></div>

    {{-- The closing sentence and the signature never part (QA8): a signature alone on a page proves nothing. --}}
    <div class="utuh">
    <div>{{ $tpl->siswaClosing() }}</div>
    <div class="s-baris"></div>
    <div class="s-baris-15"></div>

    @include('pdf::partials.ttd', [
        'ttd' => 's-ttd',
        'ttdLines' => [$tpl->city().', '.$content->issuedOnText, $tpl->signerTitle()],
    ])
    </div>
@endsection
