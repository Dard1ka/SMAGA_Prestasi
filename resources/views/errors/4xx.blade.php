{{-- Any other 4xx status (400, 402, 408, 413, …): Laravel falls back to this view. --}}
@include('errors.layout', [
    'code' => isset($exception) ? $exception->getStatusCode() : 400,
    'title' => 'Tidak bisa dibuka',
    'message' => 'Permintaan ini tidak bisa diproses.',
])
