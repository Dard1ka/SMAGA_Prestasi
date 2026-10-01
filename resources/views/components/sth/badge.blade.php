{{--
    Count pill. primary = sidebar badge (.badge, pushes itself right with margin-left:auto);
    gray = tab/lane count (.cnt).

    <x-sth.badge>2</x-sth.badge>
    <x-sth.badge tone="gray">8</x-sth.badge>
--}}
@props(['tone' => 'primary'])
<span {{ $attributes->class([$tone === 'gray' ? 'cnt' : 'badge', 'num']) }}>{{ $slot }}</span>
