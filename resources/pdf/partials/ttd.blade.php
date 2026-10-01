{{-- Signature block: date, office, the ONE signature + stamp image (settings.signature_file_id), signer from settings.
     B5: place and signer title come from the TU letter template (plain text, escaped). --}}
@php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current())
<table class="ttd">
    <tr>
        <td></td>
        <td class="kanan">
            <div>{{ $tpl->city() }}, {{ $content->issuedOnText }}</div>
            <div>{{ $tpl->signerTitle() }}</div>
            <div class="img">@isset($pdfImages['ttd.png'])<img src="{{ $pdfImages['ttd.png'] }}" alt="Tanda tangan dan stempel">@endisset</div>
            <div class="nama">{{ $content->signer['name'] }}</div>
            <div>{{ $content->signer['rank'] }}</div>
            <div>NIP {{ $content->signer['nip'] }}</div>
        </td>
    </tr>
</table>
