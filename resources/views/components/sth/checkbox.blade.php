{{--
    Single checkbox with its label on the right (e.g. confirmations before sending).

    <x-sth.checkbox name="setuju" label="Data tim sudah benar dan semua anggota setuju ikut." />
    <x-sth.checkbox wire:model.live="asli">Dokumen ini asli dan sesuai hasil lomba.</x-sth.checkbox>

    Props: name · id · label (or slot) · value ("1") · checked · hint · error
--}}
@props(['name' => null, 'id' => null, 'label' => null, 'value' => '1', 'checked' => false, 'hint' => null, 'error' => null])
@php
    $wireModel = $attributes->wire('model')->value();
    $errorKey = $wireModel ?: ($name !== null ? rtrim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null);
    $id ??= 'f-'.($errorKey !== null ? str_replace('.', '-', $errorKey) : \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)));
    $error ??= $errorKey !== null ? ($errors ?? null)?->first($errorKey) : null;
    $error = $error ?: null;
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $isChecked = ($wireModel || $name === null) ? (bool) $checked : (bool) (request()->hasSession() ? old($errorKey, $checked) : $checked);
@endphp
<div {{ $attributes->only('class')->class('flex flex-col gap-1') }}>
    <label for="{{ $id }}" class="inline-flex min-h-10 cursor-pointer items-center gap-2.5 text-sm leading-snug font-semibold">
        <input
            type="checkbox"
            id="{{ $id }}"
            @if ($name !== null) name="{{ $name }}" @endif
            value="{{ $value }}"
            class="cek"
            @checked($isChecked)
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except('class') }}
        >
        <span>@if ($slot->isNotEmpty()){{ $slot }}@else{{ $label }}@endif</span>
    </label>
    @if ($hint)
        <p id="{{ $id }}-hint" class="field-hint pl-[30px]">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="field-error pl-[30px]"><x-sth.icon name="alert" :size="16" />{{ $error }}</p>
    @endif
</div>
