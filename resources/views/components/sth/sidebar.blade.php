{{--
    Role sidebar (DESIGN-SPEC-v2 §4 app shell): brand → (siswa) "Cari lomba" button → grouped nav with
    .nav-sec labels, pill current item and count badges → user card. Data comes from
    App\Support\Navigation::for($user, $role, $badges) — the app layout passes it in.

    <x-sth.sidebar :nav="$nav" class="hidden lg:flex" />   desktop column (264px, sticky)
    <x-sth.sidebar :nav="$nav" drawer />                     inside the mobile menu <dialog>

    Items whose route does not exist yet render as disabled (aria-disabled, no href).
--}}
@props(['nav', 'drawer' => false])
@php
    $prefix = $drawer ? 'dr' : 'sb';
@endphp
<aside {{ $attributes->class([
    'flex w-[264px] shrink-0 flex-col gap-[18px] bg-surface px-4 py-[22px]',
    'sticky top-0 h-dvh overflow-y-auto border-r border-line' => ! $drawer,
    'min-h-full w-full' => $drawer,
]) }}>
    <div class="flex items-center justify-between gap-2 px-1">
        <x-sth.brand :href="$nav['homeUrl']" />
        @if ($drawer)
            <x-sth.icon-button icon="x" label="Tutup menu" size="sm" ghost data-sth-close />
        @endif
    </div>

    @if ($nav['cta'])
        <x-sth.button :href="$nav['cta']['href']" :disabled="$nav['cta']['disabled']" icon="search" block>{{ $nav['cta']['label'] }}</x-sth.button>
    @endif

    <nav aria-label="Menu utama" class="flex flex-col gap-1">
        @foreach ($nav['sections'] as $section)
            <div role="group" aria-labelledby="{{ $prefix }}-sec-{{ $loop->index }}" class="flex flex-col gap-1">
                <div id="{{ $prefix }}-sec-{{ $loop->index }}" @class(['nav-sec', 'mt-2.5' => ! $loop->first])>{{ $section['label'] }}</div>
                @foreach ($section['items'] as $item)
                    <a
                        @class(['nav', 'nav-on' => $item['current']])
                        @if ($item['disabled']) role="link" aria-disabled="true" @else href="{{ $item['href'] }}" @endif
                        @if ($item['current']) aria-current="page" @endif
                    >
                        <x-sth.icon :name="$item['icon']" />
                        <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                        @if ($item['badge'])
                            <span class="badge num">{{ $item['badge'] }}</span>
                        @endif
                        @if ($item['disabled'])
                            <span class="sr-only">(belum tersedia)</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    <div class="mt-auto flex items-center gap-2.5 rounded-2xl bg-page p-3">
        <x-sth.avatar :name="$nav['user']['name']" />
        <div class="flex min-w-0 flex-col gap-0.5">
            <span class="ellip text-sm leading-[1.3] font-bold">{{ $nav['user']['name'] }}</span>
            <span class="text-[12.5px] leading-[1.3] text-muted">{{ $nav['user']['meta'] }}</span>
        </div>
    </div>
</aside>
