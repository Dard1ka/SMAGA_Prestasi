{{-- 500: shown with APP_DEBUG=false. No query here: the cause may be the database itself. --}}
@include('errors.layout', [
    'code' => 500,
    'title' => 'Ada gangguan',
    'message' => 'Terjadi kesalahan di server.',
])
