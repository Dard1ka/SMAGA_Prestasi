{{-- 403: role/permission/policy refused (the package message, e.g. spatie's English text, is never shown). --}}
@include('errors.layout', [
    'code' => 403,
    'title' => 'Akses ditolak',
    'message' => 'Kamu tidak punya akses ke halaman ini.',
])
