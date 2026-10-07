{{-- Local preview of layouts::auth (/_dev/components/auth, ?variant=simple). The real login view is W1-C's. --}}
@php
    $variant = request()->query('variant') === 'simple' ? 'simple' : 'split';
@endphp
<x-layouts::auth title="Preview masuk" :variant="$variant">
    @if ($variant === 'simple')
        <div class="flex flex-col gap-5">
            <div class="flex flex-col gap-1.5">
                <h1 class="title-page">Ganti kata sandi</h1>
                <p class="m-0 text-md text-muted">Buat kata sandi baru untuk akunmu.</p>
            </div>
            <form class="flex flex-col gap-4" action="#" method="GET">
                <x-sth.input name="baru" type="password" label="Kata sandi baru" revealable autocomplete="new-password" size="lg" hint="Minimal 10 karakter." />
                <x-sth.input name="ulang" type="password" label="Ulangi kata sandi" revealable autocomplete="new-password" size="lg" />
                <x-sth.button type="button" size="lg" block>Simpan</x-sth.button>
            </form>
        </div>
    @else
        <div>
            <h1 class="title-page lg:text-4xl">Masuk</h1>
            <p class="m-0 mt-2 text-md leading-normal text-muted">Pakai NIS atau NISN dan kata sandi dari sekolah.</p>
        </div>
        <form class="flex flex-col gap-4" action="#" method="GET">
            <x-sth.input name="login" label="NIS / NISN" inputmode="numeric" autocomplete="username" placeholder="Ketik NIS atau NISN" size="lg" />
            <div class="flex flex-col gap-1">
                <div class="flex h-9 items-center justify-between">
                    <label class="label" for="f-password">Kata sandi</label>
                    <a class="lnk text-sm font-semibold" href="#">Lupa kata sandi?</a>
                </div>
                <x-sth.input name="password" type="password" revealable autocomplete="current-password" placeholder="Ketik kata sandi" size="lg" />
            </div>
            <x-sth.button type="button" size="lg" block>Masuk</x-sth.button>
        </form>
        <x-sth.alert>Baru pertama masuk? Kamu akan diminta mengganti kata sandi dan menghubungkan email.</x-sth.alert>
        <p class="m-0 text-center text-sm text-muted">Kesulitan masuk? Hubungi admin sekolah.</p>
    @endif
</x-layouts::auth>
