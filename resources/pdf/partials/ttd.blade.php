{{--
    Signature block: the lines above the signature, the ONE signature + stamp image (settings.signature_file_id,
    copied into the render sandbox), then the signer from settings.
    Vars: $ttd ('s-ttd' student letter: centred, name bold + underlined · 'g-ttd' guru letter · 'g-ttd-lampiran')
          · $ttdLines (list of lines above the image) · $content (LetterContent) · $pdfImages
--}}
<div class="{{ $ttd }} utuh">
    @foreach ($ttdLines as $line)
        <div>{{ $line }}</div>
    @endforeach
    <div class="gambar">@isset($pdfImages['ttd.png'])<img src="{{ $pdfImages['ttd.png'] }}" alt="Tanda tangan dan stempel">@endisset</div>
    <div class="nama">{{ $content->signer['name'] }}</div>
    <div>{{ $content->signer['rank'] }}</div>
    <div>NIP {{ $content->signer['nip'] }}</div>
</div>
