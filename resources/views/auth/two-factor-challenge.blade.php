{{-- Fortify::twoFactorChallengeView → POST two-factor.login.store (code OR recovery_code). --}}
<x-layouts::auth title="Verifikasi dua langkah" variant="simple">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Verifikasi dua langkah</h1>
            <p class="muted">Masukkan 6 angka dari aplikasi autentikator di HP.</p>
        </div>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="flex flex-col gap-4" novalidate>
            @csrf

            <x-sth.input
                name="code"
                label="Kode autentikator"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                pattern="[0-9]*"
                size="lg"
                autofocus
            />

            <x-sth.button type="submit" size="lg" block>Verifikasi</x-sth.button>
        </form>

        <details class="flex flex-col gap-3">
            <summary class="lnk cursor-pointer">Pakai kode pemulihan</summary>

            <form method="POST" action="{{ route('two-factor.login.store') }}" class="mt-3 flex flex-col gap-4" novalidate>
                @csrf

                <x-sth.input name="recovery_code" label="Kode pemulihan" autocomplete="off" autocapitalize="none" spellcheck="false" />

                <x-sth.button type="submit" variant="quiet" block>Pakai kode pemulihan</x-sth.button>
            </form>
        </details>

        <x-sth.link href="{{ route('login') }}" icon="chevron-left">Kembali ke halaman masuk</x-sth.link>
    </div>
</x-layouts::auth>
