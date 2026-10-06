{{-- Masuk (mockup Masuk.dc.html). Fortify::loginView → POST login.store. Field `identifier` = NIS/NISN, a student's
     bound email once activation is finished, or a staff username/email (App\Actions\Fortify\LoginIdentifier). --}}
<x-layouts::auth title="Masuk" variant="split">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Masuk</h1>
            <p class="muted">Pakai NIS atau NISN dan kata sandi dari sekolah.</p>
        </div>

        @if (session('status'))
            <x-sth.alert tone="success">{{ session('status') }}</x-sth.alert>
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4" novalidate>
            @csrf

            <x-sth.input
                name="identifier"
                label="NIS, NISN, atau email"
                hint="Sudah aktivasi? Kamu juga bisa masuk pakai email. Guru dan staf: username atau email."
                autocomplete="username"
                autocapitalize="none"
                spellcheck="false"
                size="lg"
                required
                autofocus
            />

            <x-sth.input
                name="password"
                type="password"
                label="Kata sandi"
                autocomplete="current-password"
                size="lg"
                revealable
                required
            />

            <div class="flex justify-end">
                <x-sth.link href="{{ route('password.request') }}">Lupa kata sandi?</x-sth.link>
            </div>

            <x-sth.button type="submit" size="lg" block>Masuk</x-sth.button>
        </form>

        <x-sth.alert>Baru pertama masuk? Kamu akan diminta menghubungkan email dulu. Setelah itu kamu boleh ganti kata sandi.</x-sth.alert>

        <p class="text-sm muted">Kesulitan masuk? Hubungi admin sekolah.</p>
    </div>
</x-layouts::auth>
