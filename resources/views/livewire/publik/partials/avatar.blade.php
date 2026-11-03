{{--
    Public avatar: initials for a named student, a neutral person icon for an anonymous one (a disabled or
    deleted account; its initials are never derived from the name). Never a photo. Decorative: the name or
    label is always written next to it.

    @include('livewire.publik.partials.avatar', ['person' => $row, 'size' => 'sm'])
    person: array with name + named (PublicAchievements) · size xs (28) | sm (32) | md (38) | lg (40) | xl (48)
--}}
@php
    $size = $size ?? 'md';
    $boxes = [
        'xs' => 'size-7',
        'sm' => 'size-8',
        'md' => 'size-[38px]',
        'lg' => 'size-10',
        'xl' => 'size-12',
    ];
    $icons = ['xs' => 14, 'sm' => 16, 'md' => 18, 'lg' => 20, 'xl' => 24];
@endphp
@if ($person['named'])
    <x-sth.avatar :name="$person['name']" :size="$size" />
@else
    <span class="avatar bg-gray-bg text-gray {{ $boxes[$size] ?? $boxes['md'] }}" aria-hidden="true">
        <x-sth.icon name="user" :size="$icons[$size] ?? 18" />
    </span>
@endif
