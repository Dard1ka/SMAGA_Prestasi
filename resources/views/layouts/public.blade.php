{{--
    Public pages (no login): sticky public header · content (max 1440, 56px side padding on desktop)
    · footer. Pages bring their own <h1> (e.g. <x-sth.page-header :level="1" …>).

    <x-layouts::public title="Peringkat" current="peringkat">…</x-layouts::public>
    Livewire: #[Layout('layouts::public', ['current' => 'prestasi'])] #[Title('Prestasi per bidang')]

    Props: title · current beranda|prestasi|peringkat · robots (e.g. 'noindex' for student detail pages)
--}}
@props(['title' => null, 'current' => null, 'robots' => null])
<!DOCTYPE html>
<html lang="id">
<head>
    @include('layouts.partials.head', ['title' => $title, 'robots' => $robots])
</head>
<body class="page flex min-h-dvh flex-col">
    <a href="#konten" class="skip-link">Lewati ke konten</a>

    <x-sth.public-header :current="$current" />

    <main id="konten" tabindex="-1" class="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-6 px-4 pt-6 pb-12 outline-none sm:px-6 md:px-14">
        {{ $slot }}
    </main>

    <x-sth.public-footer />
    <x-sth.flash />
    @livewireScripts
</body>
</html>
