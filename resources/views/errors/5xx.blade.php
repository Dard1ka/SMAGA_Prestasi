{{-- Any other 5xx status (502, 504, …): Laravel falls back to this view. No query here. --}}
@include('errors.layout', [
    'code' => isset($exception) ? $exception->getStatusCode() : 500,
    'title' => 'Ada gangguan',
    'message' => 'Terjadi kesalahan di server.',
])
