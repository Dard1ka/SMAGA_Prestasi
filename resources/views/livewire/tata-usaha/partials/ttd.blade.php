{{--
    Signature block of the HTML preview. TU never sees the real image (SECURITY §3m-5): a dashed placeholder.
    SV6, D-183: like the PDF, the 52 px slot keeps the lines where they are and the dashed box lies over it 8 px
    higher and 8 px lower (68 px, about 30 % more), so it touches the line above and the name below as the image does.
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
        <span class="relative my-1 block h-[52px] w-full">
            <span class="pointer-events-none absolute inset-x-0 -top-2 -bottom-2 flex items-center justify-center border border-dashed border-line-dashed font-sans text-[10px] text-muted">Tanda tangan + stempel</span>
        </span>
        <span @class(['font-bold underline' => $ttd === 'siswa'])>{{ $content->signer['name'] }}</span>
        <span>{{ $content->signer['rank'] }}</span>
        <span>NIP {{ $content->signer['nip'] }}</span>
    </div>
</div>
