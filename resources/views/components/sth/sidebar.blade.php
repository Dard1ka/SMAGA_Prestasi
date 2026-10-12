{{--
    Role sidebar (DESIGN-SPEC-v3 §4 app shell): brand → grouped nav with .nav-sec labels, a tinted current
    item and count badges → user card. One way to each place: the student's "Cari lomba" is a menu item
    only (the button that used to sit above the menu said the same thing twice). Data comes from
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

    <nav aria-label="Menu utama" class="flex flex-col gap-1">
        @foreach ($nav['sections'] as $section)
            <div role="group" aria-labelledby="{{ $prefix }}-sec-{{ $loop->index }}" class="flex flex-col gap-1">
                <div id="{{ $prefix }}-sec-{{ $loop->index }}" @class(['nav-sec', 'mt-2.5' => ! $loop->first])>{{ $section['label'] }}</div>
                @foreach ($section['items'] as $item)
                    <a
                        @class(['nav', 'nav-on' => $item['current']])
                        @if ($item['disabled']) role="link" aria-disabled="true" @else href="{{ $item['href'] }}" @if ($navAttr = \App\Support\Links::navigateAttribute($item['href'])) {{ $navAttr }} @endif @endif
                        @if ($item['current']) aria-current="page" @endif
                    >
                        <x-sth.icon :name="$item['icon']" />
                        <span class="min-w-0 flex-1 leading-snug">{{ $item['label'] }}</span>
                        {{-- Always printed for items with a badge key, so the browser event `sth-badges` (app.js) can
                             change or hide the count after a Livewire action without a page reload. --}}
                        @if ($item['badgeKey'] ?? null)
                            <span class="badge num" data-sth-badge="{{ $item['badgeKey'] }}" @if (! $item['badge']) hidden @endif>{{ $item['badge'] ?? 0 }}</span>
                        @elseif ($item['badge'])
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

    <div class="mt-auto flex items-center gap-2.5 rounded-card bg-page p-3">
        <x-sth.avatar :name="$nav['user']['name']" />
        <div class="flex min-w-0 flex-col gap-0.5">
            <span class="ellip text-sm leading-[1.3] font-bold">{{ $nav['user']['name'] }}</span>
            <span class="text-xs leading-[1.3] text-muted">{{ $nav['user']['meta'] }}</span>
        </div>
    </div>
</aside>
