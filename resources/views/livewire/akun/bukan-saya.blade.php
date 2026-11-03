{{-- "Bukan saya?" (K8, B1): opened from the email-binding notice. GET only shows; the button freezes the account. --}}
<div class="flex flex-col gap-6">
    @if ($state === 'ask')
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Tidak merasa mengubah email?</h1>
            <p class="muted">Bekukan akunnya sekarang. Akun tidak bisa dipakai masuk sampai Admin memeriksanya, dan semua perangkat dikeluarkan.</p>
        </div>
        <x-sth.alert tone="warning">Jika memang menghubungkan atau mengganti email sendiri, tutup halaman ini.</x-sth.alert>
        <x-sth.button variant="danger" size="lg" block wire:click="freeze" wire:loading.attr="disabled">Bekukan akun</x-sth.button>
    @elseif ($state === 'done')
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Akun sudah dibekukan</h1>
            <p class="muted">Admin sudah diberi tahu. Datang ke Admin atau Kesiswaan dengan kartu pelajar atau kartu pegawai untuk memulihkan akun.</p>
        </div>
        <x-sth.alert tone="success">Semua perangkat yang masuk ke akun itu sudah dikeluarkan.</x-sth.alert>
    @else
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Link tidak berlaku</h1>
            <p class="muted">Link ini sudah dipakai atau sudah lewat 7 hari. Jika akun bermasalah, hubungi Admin atau Kesiswaan.</p>
        </div>
    @endif
</div>
