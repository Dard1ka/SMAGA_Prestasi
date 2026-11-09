{{--
    SV8, D-194: letterhead of the guru letter as on the school's PDF of 2026-11-09 (measures: .kop-g in
    layout.blade.php). The two emblems stand at their own places (position: absolute; the Pemprov emblem is the one of
    that PDF, resources/pdf/assets/logo-pemprov-guru.png), the lines of the TU letter template (plain text, escaped)
    are centred in a box wider than the gap between them, a web address host is blue and underlined
    (LetterTemplate::kopLineParts()), then the thick + thin rule.
    Vars: $tpl / $letterTemplate (optional)
--}}
@php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
<div class="kop-g">
    <img class="kiri" src="{{ resource_path('pdf/assets/logo-pemprov-guru.png') }}" alt="Lambang Provinsi Jawa Tengah">
    <img class="kanan" src="{{ resource_path('pdf/assets/logo-sekolah.jpg') }}" alt="Lambang SMA Negeri 3 Semarang">
    <div class="kop-teks">
        @if ($tpl->get('kop_1') !== '')<div class="kop-1">{{ $tpl->get('kop_1') }}</div>@endif
        @if ($tpl->get('kop_2') !== '')<div class="kop-2">{{ $tpl->get('kop_2') }}</div>@endif
        @if ($tpl->get('kop_3') !== '')<div class="kop-3">{{ $tpl->get('kop_3') }}</div>@endif
        <div class="kop-rinci">
            @foreach ($tpl->kopDetailLines() as $line)
                {{-- one line on purpose: whitespace between the pieces would print as a space --}}
                <div class="kop-4">@foreach (\App\Actions\Templates\LetterTemplate::kopLineParts($line) as $part)<span @class(['tautan' => $part['link']])>{{ $part['text'] }}</span>@endforeach</div>
            @endforeach
        </div>
    </div>
</div>
<div class="kop-garis-g">
    <div class="garis-tebal"></div>
    <div class="garis-tipis"></div>
</div>
