{{-- Signature block of the HTML preview. TU never sees the real image (SECURITY §3m-5): a dashed placeholder. --}}
<div class="relative mt-2 flex justify-end">
    <div class="flex w-[46%] flex-col gap-0.5">
        @php($tpl ??= $letterTemplate ?? \App\Actions\Templates\LetterTemplate::current()) {{-- B5: TU letter template --}}
        <span>{{ $tpl->city() }}, {{ $content->issuedOnText }}</span>
        <span>{{ $tpl->signerTitle() }}</span>
        <span class="my-1 flex h-16 items-center justify-center border border-dashed border-line-dashed font-sans text-[10.5px] text-muted">Tanda tangan + stempel</span>
        <span class="font-bold underline">{{ $content->signer['name'] }}</span>
        <span>{{ $content->signer['rank'] }}</span>
        <span>NIP {{ $content->signer['nip'] }}</span>
    </div>
</div>
