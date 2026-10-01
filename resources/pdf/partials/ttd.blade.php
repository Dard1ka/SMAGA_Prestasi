{{-- Signature block: date, office, the ONE signature + stamp image (settings.signature_file_id), signer from settings. --}}
<table class="ttd">
    <tr>
        <td></td>
        <td class="kanan">
            <div>{{ \App\Actions\Letters\LetterContent::CITY }}, {{ $content->issuedOnText }}</div>
            <div>Kepala Sekolah</div>
            <div class="img">@isset($pdfImages['ttd.png'])<img src="{{ $pdfImages['ttd.png'] }}" alt="Tanda tangan dan stempel">@endisset</div>
            <div class="nama">{{ $content->signer['name'] }}</div>
            <div>{{ $content->signer['rank'] }}</div>
            <div>NIP {{ $content->signer['nip'] }}</div>
        </td>
    </tr>
</table>
