{{-- Lupa kata sandi: 1 NISN atau email → 2 kode dari email + kata sandi baru. Same message whether or not the account exists. --}}
<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <h1 class="title-page">Lupa kata sandi</h1>
        <p class="muted">
            @if ($step === 1)
                Kami kirim kode ke email yang terhubung dengan akun.
            @else
                Masukkan kode dari email dan buat kata sandi baru.
            @endif
        </p>
    </div>

    @if (session('status'))
        <x-sth.alert tone="success">{{ session('status') }}</x-sth.alert>
    @endif

    @if ($step === 1)
        <form wire:submit="sendCode" class="flex flex-col gap-4" novalidate>
            <x-sth.input
                wire:model="identifier"
                label="NISN atau email"
                hint="Guru dan staf: username atau email."
                autocomplete="username"
                autocapitalize="none"
                spellcheck="false"
                size="lg"
            />
            <x-sth.button type="submit" size="lg" block wire:loading.attr="disabled">Kirim kode</x-sth.button>
        </form>

        <x-sth.alert>Belum pernah menghubungkan email? Hubungi Admin atau Kesiswaan.</x-sth.alert>
    @else
        @if ($testMode)
            <x-sth.alert tone="warning">Mode uji: kode apa pun diterima.</x-sth.alert>
        @endif

        <form wire:submit="resetPassword" class="flex flex-col gap-4" novalidate>
            <x-sth.input
                wire:model="code"
                label="Kode verifikasi"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                pattern="[0-9]*"
                size="lg"
            />
            <x-sth.input
                wire:model="password"
                type="password"
                label="Kata sandi baru"
                :hint="$passwordHint"
                autocomplete="new-password"
                size="lg"
                revealable
            />
            <x-sth.input
                wire:model="password_confirmation"
                type="password"
                label="Ulangi kata sandi"
                autocomplete="new-password"
                size="lg"
                revealable
            />
            <x-sth.button type="submit" size="lg" block wire:loading.attr="disabled">Simpan kata sandi</x-sth.button>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <button type="button" class="lnk" wire:click="resendCode">Kirim ulang kode</button>
            <button type="button" class="lnk" wire:click="startOver">Ulangi dari awal</button>
        </div>
    @endif

    <x-sth.link href="{{ route('login') }}" icon="chevron-left">Kembali ke halaman masuk</x-sth.link>
</div>
