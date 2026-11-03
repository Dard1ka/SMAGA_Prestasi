{{-- 419: the form's CSRF token expired (page left open, or logged out in another tab). --}}
@include('errors.layout', [
    'code' => 419,
    'title' => 'Kedaluwarsa',
    'message' => 'Halaman kedaluwarsa. Muat ulang lalu coba lagi.',
])
