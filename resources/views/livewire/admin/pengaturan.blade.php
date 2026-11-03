{{-- Admin › Pengaturan (mockup Admin-Pengaturan.dc.html): vertical tabs (links, ?tab=) + the active tab component. --}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-[240px_minmax(0,1fr)]">
    <nav aria-label="Bagian pengaturan" class="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:overflow-visible lg:px-0">
        <ul class="flex gap-2 lg:sticky lg:top-24 lg:flex-col lg:gap-1">
            @foreach ($tabs as $key => $item)
                <li class="shrink-0">
                    <a href="{{ route('admin.pengaturan', ['tab' => $key]) }}"
                       @if ($tab === $key) aria-current="page" @endif
                       @class([
                           'flex min-h-11 items-center gap-3 rounded-control px-3.5 text-md font-semibold whitespace-nowrap no-underline',
                           'bg-primary-tint text-primary-on-tint' => $tab === $key,
                           'text-ink-2 hover:bg-hover hover:text-ink' => $tab !== $key,
                       ])>
                        <x-sth.icon :name="$item['icon']" />
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="min-w-0">
        @switch($tab)
            @case('bobot')
                @livewire(\App\Livewire\Admin\Pengaturan\Bobot::class, [], key('tab-bobot'))
                @break
            @case('batas')
                @livewire(\App\Livewire\Admin\Pengaturan\BatasArsip::class, [], key('tab-batas'))
                @break
            @case('surat')
                @livewire(\App\Livewire\Admin\Pengaturan\Surat::class, [], key('tab-surat'))
                @break
            @case('pengguna')
                @livewire(\App\Livewire\Admin\Pengaturan\Pengguna::class, [], key('tab-pengguna'))
                @break
            @case('email')
                @livewire(\App\Livewire\Admin\Pengaturan\Email::class, [], key('tab-email'))
                @break
            @case('publik')
                @livewire(\App\Livewire\Admin\Pengaturan\Publik::class, [], key('tab-publik'))
                @break
        @endswitch
    </div>
</div>
