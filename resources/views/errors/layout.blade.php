{{--
    Error page in the app's look (M2-C). Every errors/<status>.blade.php includes this file:

        @include('errors.layout', ['code' => 404, 'title' => 'Tidak ditemukan', 'message' => 'Halaman tidak ditemukan.'])

    Rules (they are the reason this is not <x-layouts::app>):
    - it must render for guests, for every role, in maintenance mode and while the database is down:
      no query, no role menu, no Livewire component;
    - CSS only through the shared head partial (@vite prints the CSP nonce); no style="" and no inline script;
    - never print $exception->getMessage(): package messages are English and may leak internals.

    Variables: code (HTTP status) · title (short, sentence case) · message (one sentence)
               home · back — set by App\Providers\ErrorPageServiceProvider (view composer):
               home = /beranda when signed in, else the public home; back = previous page of this app or null.
--}}
@php
    // Defaults for the rare case that the composer did not run (an error before the providers booted).
    $home ??= url('/');
    $back ??= null;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    @include('layouts.partials.head', ['title' => $title, 'robots' => 'noindex'])
</head>
<body class="page min-h-dvh">
    <a href="#konten" class="skip-link">Lewati ke konten</a>

    <div class="flex min-h-dvh flex-col items-center px-4 py-8 sm:py-12">
        <x-sth.brand :href="url('/')" :navigate="false" />

        <main id="konten" tabindex="-1" class="card mt-8 w-full max-w-[440px] p-6 outline-none sm:p-8">
            <div class="flex flex-col gap-6">
                <div class="flex flex-col gap-2">
                    <x-sth.chip class="num self-start">Kode {{ $code }}</x-sth.chip>
                    <h1 class="title-page">{{ $title }}</h1>
                    <p class="muted m-0">{{ $message }}</p>
                </div>

                <div class="flex flex-col gap-2.5">
                    <x-sth.button :href="$home" icon="home" block :navigate="false">Kembali ke beranda</x-sth.button>
                    @if ($back)
                        <x-sth.button variant="quiet" :href="$back" icon="arrow-left" block :navigate="false">Kembali</x-sth.button>
                    @endif
                </div>
            </div>
        </main>
    </div>
</body>
</html>
