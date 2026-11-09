{{--
    Signature block of the HTML preview. TU never sees the real image (SECURITY §3m-5): a dashed placeholder that lies
    over the lines around its slot as the image does on the PDF, while the slot keeps the lines where they are.
    SV9, D-200 (one 92 pt image on both letters, 30 pt = 28 px left): student letter 52 px slot, box 87 px high and
    174 px wide (a 2:1 image), 28 px left of the block's middle; guru letter (from sm up at the PDF's place, x 333.3 pt)
    the three empty lines of the school's signature space (42 px), the same 87 x 174 px box, 28 px left of the text. A guru letter printed before D-194 (`lampiran`) keeps its old preview (SV6, D-183).
    Vars: $ttd ('siswa' = centred, name bold + underlined · 'guru' = the D-194 guru letter · 'lampiran' = a guru letter
    printed before it) · $ttdLines (lines above the signature; default "<kota>, <tanggal surat>" + the signer title)
--}}
@php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current()) {{-- B5: TU letter template --}}
@php($ttdLines ??= [$tpl->city().', '.($ttd === 'guru' ? $content->guruSignedOn() : $content->issuedOnText), $tpl->signerTitle()])
<div @class(['relative flex justify-end', 'sm:block' => $ttd === 'guru'])>
    <div @class([
        'flex flex-col',
        'w-[48%] items-center text-center' => $ttd === 'siswa',
        'w-[47%]' => $ttd !== 'siswa',
        'sm:ml-[261.4px] sm:w-[225.6px]' => $ttd === 'guru',
    ])>
        @foreach ($ttdLines as $line)
            <span>{{ $line }}</span>
        @endforeach
        <span @class(['relative block w-full', 'my-1 h-[52px]' => $ttd !== 'guru', 'h-[42px]' => $ttd === 'guru'])>
            <span @class([
                'pointer-events-none absolute flex items-center justify-center border border-dashed border-line-dashed font-sans text-[10px] text-muted',
                'inset-x-0 mx-auto -top-[17.5px] -bottom-[17.5px] w-[174px] max-w-full -translate-x-7' => $ttd === 'siswa',
                '-top-[22.5px] -bottom-[22.5px] left-0 w-[174px] max-w-full -translate-x-7' => $ttd === 'guru',
                'inset-x-0 -top-2 -bottom-2' => $ttd === 'lampiran',
            ])>Tanda tangan + stempel</span>
        </span>
        <span @class(['font-bold underline' => $ttd === 'siswa'])>{{ $content->signer['name'] }}</span>
        <span>{{ $content->signer['rank'] }}</span>
        <span>NIP {{ $content->signer['nip'] }}</span>
    </div>
</div>
