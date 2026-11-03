{{--
    One toast (white card, tinted icon, close button). Normally you do not render this yourself:
    - after a redirect: session()->flash('toast', 'Usulan terkirim.') or ['tone' => 'error', 'message' => '…']
      → <x-sth.flash> in every layout renders it;
    - from Livewire: $this->dispatch('sth-toast', message: 'Lomba dipublikasikan.', tone: 'success');
    Screen readers get the text through the layout's live region (resources/js/app.js). success/info
    close after 6 s (paused on hover/focus); warning/error stay until closed, or until the screen that raised
    them with a key says the failure is over: dispatch('sth-toast', …, key: 'surat-tugas') and later
    dispatch('sth-toast-clear', key: 'surat-tugas') (e.g. TataUsaha\SuratTugas).

    <x-sth.toast tone="success">Lomba dipublikasikan.</x-sth.toast>

    Props: tone success | info | warning | error · message (or slot) · dismissible (default true)
--}}
@props(['tone' => 'success', 'message' => null, 'dismissible' => true])
@php
    $map = [
        'success' => ['bg-green-bg text-green', 'check'],
        'info' => ['bg-blue-bg text-blue', 'info'],
        'warning' => ['bg-orange-bg text-orange', 'clock'],
        'error' => ['bg-red-bg text-red', 'alert'],
    ];
    $tone = array_key_exists($tone, $map) ? $tone : 'success';
    [$iconClass, $icon] = $map[$tone];
@endphp
<div {{ $attributes->class('toast') }} data-sth-toast data-tone="{{ $tone }}">
    <span class="toast-ic {{ $iconClass }}"><x-sth.icon :name="$icon" :size="18" /></span>
    <span class="min-w-0 flex-1" data-sth-toast-text>@if ($message !== null){{ $message }}@else{{ $slot }}@endif</span>
    @if ($dismissible)
        <button type="button" class="icon-btn icon-btn-ghost icon-btn-sm" aria-label="Tutup pesan" data-sth-toast-close>
            <x-sth.icon name="x" :size="18" />
        </button>
    @endif
</div>
