{{-- Letterhead. Logos are placeholders until the school confirms its template (D-06, D-20: no official emblem in the repo).
     B5: lines come from the TU letter template (App\Actions\Templates\LetterTemplate, plain text, escaped). --}}
@php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
<table class="kop">
    <tr>
        <td class="logo"><div>Logo Pemprov</div></td>
        <td>
            @if ($tpl->get('kop_1') !== '')<div class="l1">{{ $tpl->get('kop_1') }}</div>@endif
            @if ($tpl->get('kop_2') !== '')<div class="l2">{{ $tpl->get('kop_2') }}</div>@endif
            @if ($tpl->get('kop_3') !== '')<div class="l3">{{ $tpl->get('kop_3') }}</div>@endif
            @foreach ($tpl->kopDetailLines() as $line)<div class="l4">{{ $line }}</div>@endforeach
        </td>
        <td class="logo"><div>Logo sekolah</div></td>
    </tr>
</table>
