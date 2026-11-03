{{-- 401: abort(401) only; a guest on a protected page is redirected to /login by the `auth` middleware. --}}
@include('errors.layout', [
    'code' => 401,
    'title' => 'Belum masuk',
    'message' => 'Masuk dulu untuk membuka halaman ini.',
])
