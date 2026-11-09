{{--
    SV8, D-194 (LetterTemplate::GURU_LAYOUT_LANGSUNG): the Surat Perintah Tugas laid out like the school's PDF of
    2026-11-09, one page, no Lampiran: kop · SURAT PERINTAH TUGAS · "Nomor <nomor>" (no colon) · "Dasar" (the regulation
    texts of the TU letter template, then the invitation "Surat dari … Nomor … Tanggal … Hal …") · MEMERINTAHKAN: ·
    "Kepada : 1. Nama / Pangkat/Gol / NIP / Jabatan" with the one guru of the registration · "Untuk" (1. the kegiatan in
    bold, 2. and 3. from the template) · signature. Measures and spacers: .kop-g and .g-* in layout.blade.php.
    Vars: $content (LetterContent) · $number · $tpl (LetterTemplate) · $pdfImages
--}}
@php($basisItems = array_merge($tpl->guruBasisItems($content->schoolYear), [$content->guruBasis()]))
@php([$taskBefore, $taskKegiatan, $taskRest] = $content->guruTaskParts())
{{-- The justified texts are set line by line the way the school's Word file does (JustifiedLines): every line but
     the last ends one space before the column edge at 551.8 pt, i.e. at 548.46 pt; the column starts at 181.05 pt under
     "Dasar" and 0.2 pt further left under "Untuk" (367.41 / 367.61 pt wide: .g-daftar .isi, .g-untuk .isi). --}}
@php($justify = \App\Domain\Letters\JustifiedLines::helvetica(12))
@php($plain = fn (string $text): array => [['text' => $text, 'bold' => false]])
@include('pdf::partials.kop-guru')

<div class="g-jarak-judul"></div>
<div class="g-judul">SURAT PERINTAH TUGAS</div>
<div class="g-nomor">Nomor {{ $number }}</div>

<div class="g-jarak-dasar"></div>
<table class="g-daftar g-dasar">
    @foreach ($basisItems as $item)
        <tr>
            <td class="k">{{ $loop->first ? 'Dasar' : '' }}</td>
            <td class="s">{{ $loop->first ? ':' : '' }}</td>
            <td class="n">{{ $loop->iteration }}.</td>
            <td class="isi">@include('pdf::partials.guru-paragraf', ['lines' => $justify->split($plain($item), 367.41)])</td>
        </tr>
    @endforeach
</table>

<div class="g-jarak-perintah"></div>
<div class="g-perintah">MEMERINTAHKAN:</div>

{{-- One guru per registration: the four rows stand right under "Kepada", each label with its own colon. --}}
<div class="g-jarak-kepada"></div>
<table class="g-kepada">
    <tr><td class="k">Kepada</td><td class="s">:</td><td class="n">1.</td><td class="label">Nama</td><td class="titik">:</td><td class="nilai">{{ $content->mentor['name'] ?? '-' }}</td></tr>
    <tr><td class="k"></td><td class="s"></td><td class="n"></td><td class="label">Pangkat/Gol</td><td class="titik">:</td><td class="nilai">{{ $content->mentor['rank'] ?? '-' }}</td></tr>
    <tr><td class="k"></td><td class="s"></td><td class="n"></td><td class="label">NIP</td><td class="titik">:</td><td class="nilai">{{ $content->mentor['nip'] ?? '-' }}</td></tr>
    <tr><td class="k"></td><td class="s"></td><td class="n"></td><td class="label">Jabatan</td><td class="titik">:</td><td class="nilai">{{ $content->mentor['position'] ?? 'Guru' }}</td></tr>
</table>

{{-- "Untuk" and the signature never part: with a long lomba name and place the signature alone went to page 2 (QA8).
     If they do not fit, both start the next page. --}}
<div class="utuh">
    <div class="g-jarak-untuk"></div>
    <table class="g-daftar g-untuk">
        @foreach ([
            [['text' => $taskBefore, 'bold' => false], ['text' => $taskKegiatan, 'bold' => true], ['text' => $taskRest, 'bold' => false]],
            $plain($tpl->guruReport()),
            $plain($tpl->guruClosing()),
        ] as $runs)
            <tr>
                <td class="k">{{ $loop->first ? 'Untuk' : '' }}</td>
                <td class="s">{{ $loop->first ? ':' : '' }}</td>
                <td class="n">{{ $loop->iteration }}.</td>
                <td class="isi">@include('pdf::partials.guru-paragraf', ['lines' => $justify->split($runs, 367.61)])</td>
            </tr>
        @endforeach
    </table>

    <div class="g-jarak-ttd"></div>
    @include('pdf::partials.ttd', [
        'ttd' => 'g-ttd',
        'ttdLines' => [$tpl->city().', '.$content->guruSignedOn(), $tpl->signerTitle()],
    ])
</div>
