{{--
    405: an address typed into the browser that only accepts a form (POST). Having this view also keeps the
    answer small with APP_DEBUG=true: without it Laravel builds the full debug page (QA Q2-03).
--}}
@include('errors.layout', [
    'code' => 405,
    'title' => 'Tidak bisa dibuka',
    'message' => 'Alamat ini tidak bisa dibuka langsung.',
])
