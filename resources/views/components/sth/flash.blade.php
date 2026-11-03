{{--
    Toast region + screen-reader live regions. Every layout renders it once, near the end of <body>.
    Reads the session flash:
      session()->flash('toast', 'Usulan lomba terkirim.');                          → success
      session()->flash('toast', ['tone' => 'info', 'message' => 'Data disimpan.']);  → any tone
      session()->flash('error', 'Kode salah atau kedaluwarsa.');                      → error
    Livewire: $this->dispatch('sth-toast', message: '…', tone: 'success') adds one without reload.
    Also carries the dialog #sth-sesi-habis (D-170): app.js opens it when a Livewire request answers 419 (the session
    ended or the page was open too long), instead of Livewire's native English confirm. Every layout that loads
    Livewire renders this component, so every Livewire page has the dialog.
--}}
@php
    $items = [];
    $toast = session('toast');
    if (is_string($toast) && $toast !== '') {
        $items[] = ['tone' => 'success', 'message' => $toast];
    } elseif (is_array($toast) && ! empty($toast['message'])) {
        $items[] = ['tone' => (string) ($toast['tone'] ?? 'success'), 'message' => (string) $toast['message']];
    }
    $error = session('error');
    if (is_string($error) && $error !== '') {
        $items[] = ['tone' => 'error', 'message' => $error];
    }
@endphp
<div id="sth-toasts" class="toast-region" {{ $attributes }}>
    @foreach ($items as $item)
        <x-sth.toast :tone="$item['tone']" :message="$item['message']" data-announce />
    @endforeach
</div>
<div id="sth-live" class="sr-only" aria-live="polite" aria-atomic="true"></div>
<div id="sth-live-assertive" class="sr-only" aria-live="assertive" aria-atomic="true"></div>
<x-sth.modal id="sth-sesi-habis" title="Sesi sudah berakhir" description="Muat ulang halaman untuk melanjutkan." size="sm">
    <p class="m-0 text-sm">Isian yang belum disimpan perlu diisi lagi setelah halaman dimuat ulang. Bila diminta, masuk lagi dengan akun yang sama.</p>
    <x-slot:footer>
        <x-sth.button variant="quiet" data-sth-close>Tutup</x-sth.button>
        <x-sth.button data-sth-reload>Muat ulang</x-sth.button>
    </x-slot:footer>
</x-sth.modal>
