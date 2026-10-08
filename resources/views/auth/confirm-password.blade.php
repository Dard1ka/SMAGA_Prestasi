{{-- Fortify::confirmPasswordView (middleware password.confirm) → POST password.confirm.store. $back = "Batal" (FortifyServiceProvider::confirmPasswordBack). --}}
<x-layouts::auth title="Konfirmasi kata sandi" variant="simple">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Konfirmasi kata sandi</h1>
            <p class="muted">Demi keamanan, tulis kata sandi sekali lagi.</p>
        </div>

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-4" novalidate>
            @csrf

            <x-sth.input
                name="password"
                type="password"
                label="Kata sandi"
                autocomplete="current-password"
                size="lg"
                revealable
                required
                autofocus
            />

            <x-sth.button type="submit" size="lg" block>Lanjut</x-sth.button>
            <x-sth.button variant="quiet" size="lg" block href="{{ $back }}" :navigate="false">Batal</x-sth.button>
        </form>
    </div>
</x-layouts::auth>
