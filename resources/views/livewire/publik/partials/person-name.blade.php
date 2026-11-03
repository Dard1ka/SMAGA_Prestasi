{{--
    Student name (or anonymous label). Named students with a public slug link to their page.

    @include('livewire.publik.partials.person-name', ['person' => $row, 'class' => 'text-sm'])
    @include('livewire.publik.partials.person-name', ['person' => $row, 'wrap' => true])   table cells on phones
--}}
@php
    $flow = ($wrap ?? false) ? 'break-words' : 'truncate';
@endphp
@if ($person['slug'] !== null)
    <a href="{{ route('public.prestasi.siswa', $person['slug']) }}" class="min-w-0 {{ $flow }} font-bold text-ink hover:text-primary {{ $class ?? '' }}">{{ $person['name'] }}</a>
@else
    <span @class(['min-w-0 font-bold', $flow, 'text-ink' => $person['named'], 'text-ink-2' => ! $person['named'], $class ?? ''])>{{ $person['name'] }}</span>
@endif
