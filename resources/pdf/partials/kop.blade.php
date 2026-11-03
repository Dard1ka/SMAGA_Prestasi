{{--
    Letterhead of both letters, as on the school's own files (M1-A): Pemprov Jawa Tengah emblem left, school
    emblem right (resources/pdf/assets, inside the dompdf chroot), the lines of the TU letter template in the
    middle (App\Actions\Templates\LetterTemplate, plain text, escaped), then a thick + thin rule.
    Vars: $kop ('guru' | 'siswa': sizes and offsets in layout.blade.php) · $tpl / $letterTemplate (optional)
--}}
@php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
<table class="kop kop-{{ $kop }}">
    <tr>
        <td class="kop-kiri"><img src="{{ resource_path('pdf/assets/logo-pemprov.png') }}" alt="Lambang Provinsi Jawa Tengah"></td>
        <td class="kop-teks">
            @if ($tpl->get('kop_1') !== '')<div class="kop-1">{{ $tpl->get('kop_1') }}</div>@endif
            @if ($tpl->get('kop_2') !== '')<div class="kop-2">{{ $tpl->get('kop_2') }}</div>@endif
            @if ($tpl->get('kop_3') !== '')<div class="kop-3">{{ $tpl->get('kop_3') }}</div>@endif
            @foreach ($tpl->kopDetailLines() as $line)<div class="kop-4">{{ $line }}</div>@endforeach
        </td>
        <td class="kop-kanan"><img src="{{ resource_path('pdf/assets/logo-sekolah.jpg') }}" alt="Lambang SMA Negeri 3 Semarang"></td>
    </tr>
</table>
<div class="kop-garis-{{ $kop }}">
    <div class="garis-tebal"></div>
    <div class="garis-tipis"></div>
</div>
