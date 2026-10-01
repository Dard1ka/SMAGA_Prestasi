{{-- Staff TOTP setup (Fortify actions). QR is an <img> data URI, so no unescaped output is needed. --}}
<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h1 class="title-page">Verifikasi dua langkah</h1>
        <p class="muted">Akun staf wajib memakai kode dari aplikasi autentikator (misalnya Google Authenticator).</p>
    </div>

    @if ($showRecoveryCodes)
        <x-sth.alert tone="success" title="Verifikasi dua langkah aktif">
            Simpan kode pemulihan ini di tempat aman. Setiap kode hanya bisa dipakai sekali.
        </x-sth.alert>

        <ul class="grid grid-cols-2 gap-2 font-mono text-sm" aria-label="Kode pemulihan">
            @foreach ($recoveryCodes as $recoveryCode)
                <li class="card px-3 py-2">{{ $recoveryCode }}</li>
            @endforeach
        </ul>

        <x-sth.button size="lg" block wire:click="finish">Selesai</x-sth.button>
    @elseif ($confirmed)
        <x-sth.alert tone="success">Verifikasi dua langkah sudah aktif.</x-sth.alert>

        <x-sth.button size="lg" block wire:click="finish">Lanjut</x-sth.button>
    @else
        <ol class="flex flex-col gap-4">
            <li class="flex flex-col gap-3">
                <p><span class="font-semibold">1.</span> Pindai kode QR ini dengan aplikasi autentikator.</p>
                <img src="{{ $qrDataUri }}" alt="Kode QR verifikasi dua langkah" width="192" height="192" class="rounded-xl border border-line bg-white p-2">
                <p class="text-sm muted">Tidak bisa memindai? Masukkan kunci ini: <span class="font-mono font-semibold">{{ $secret }}</span></p>
            </li>
            <li>
                <form wire:submit="confirm" class="flex flex-col gap-4" novalidate>
                    <x-sth.input
                        wire:model="code"
                        label="2. Masukkan 6 angka dari aplikasi"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        pattern="[0-9]*"
                        size="lg"
                    />
                    <x-sth.button type="submit" size="lg" block wire:loading.attr="disabled">Aktifkan</x-sth.button>
                </form>
            </li>
        </ol>
    @endif
</div>
