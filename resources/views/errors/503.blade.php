{{-- 503: maintenance mode (php artisan down) during a deploy. --}}
@include('errors.layout', [
    'code' => 503,
    'title' => 'Sedang dirawat',
    'message' => 'Aplikasi sedang dirawat.',
])
