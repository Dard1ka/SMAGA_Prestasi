{{-- 429: a route rate limit (public pages, downloads). Livewire's own limit answers with plain text + toast. --}}
@include('errors.layout', [
    'code' => 429,
    'title' => 'Coba lagi nanti',
    'message' => 'Terlalu banyak permintaan. Tunggu sebentar.',
])
