{{--
    Inline note box (tinted, icon + text). For page-level messages after an action use <x-sth.toast>.

    <x-sth.alert>Baru pertama masuk? Kamu akan diminta mengganti kata sandi dan menghubungkan email.</x-sth.alert>
    <x-sth.alert tone="error">NISN atau kata sandi salah.</x-sth.alert>
    <x-sth.alert tone="warning" title="Perlu tindakan">Laporkan hasil WICE 2026 sebelum 24 Okt 2026.</x-sth.alert>

    Props: tone info (blue, default) | success (green) | warning (orange) | error (red) · title · icon
    Errors use role="alert" (announced at once); others role="status".
--}}
@props(['tone' => 'info', 'title' => null, 'icon' => null])
@php
    $map = [
        'info' => ['c-blue', 'info'],
        'success' => ['c-green', 'check-circle'],
        'warning' => ['c-orange', 'clock'],
        'error' => ['c-red', 'alert'],
    ];
    [$toneClass, $defaultIcon] = $map[$tone] ?? $map['info'];
@endphp
<div role="{{ $tone === 'error' ? 'alert' : 'status' }}" {{ $attributes->class(['alert', $toneClass]) }}>
    <x-sth.icon :name="$icon ?? $defaultIcon" :size="18" />
    <div class="min-w-0">
        @if ($title)
            <p class="m-0 font-bold">{{ $title }}</p>
        @endif
        <div>{{ $slot }}</div>
    </div>
</div>
