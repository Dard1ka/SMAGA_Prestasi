{{-- Temporary landing for `home` and `siswa.tour` until wave 2 builds the role areas. --}}
<x-layouts::auth :title="$title" variant="simple">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-2">
            <h1 class="title-page">{{ $title }}</h1>
            <p class="muted">{{ $message }}</p>
        </div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-sth.button type="submit" variant="quiet" block>Keluar</x-sth.button>
        </form>
    </div>
</x-layouts::auth>
