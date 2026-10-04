{{--
    Signature block of the HTML preview. TU never sees the real image (SECURITY §3m-5): a dashed placeholder.
    Vars: $ttd ('siswa' = centred, name bold + underlined · 'guru' = left-aligned) · $ttdLines (lines above the
    signature; default "<kota>, <tanggal surat>" + the signer title)
--}}
@php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current()) {{-- B5: TU letter template --}}
@php($ttdLines ??= [$tpl->city().', '.$content->issuedOnText, $tpl->signerTitle()])
<div class="relative flex justify-end">
    <div @class(['flex flex-col', 'w-[48%] items-center text-center' => $ttd === 'siswa', 'w-[47%]' => $ttd !== 'siswa'])>
        @foreach ($ttdLines as $line)
            <span>{{ $line }}</span>
        @endforeach
        <span class="my-1 flex h-[52px] w-full items-center justify-center border border-dashed border-line-dashed font-sans text-[10px] text-muted">Tanda tangan + stempel</span>
        <span @class(['font-bold underline' => $ttd === 'siswa'])>{{ $content->signer['name'] }}</span>
        <span>{{ $content->signer['rank'] }}</span>
        <span>NIP {{ $content->signer['nip'] }}</span>
    </div>
</div>
