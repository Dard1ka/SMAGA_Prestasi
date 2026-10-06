{{-- 404: unknown address, or a record the visitor may not see (not-yours → 404, CLAUDE.md §3). --}}
@include('errors.layout', [
    'code' => 404,
    'title' => 'Tidak ditemukan',
    'message' => 'Halaman tidak ditemukan.',
])
