{{--
    Multi-line text with label, hint and error.

    <x-sth.textarea name="catatan" label="Catatan untuk siswa" rows="3" required />
    <x-sth.textarea wire:model="form.abstract" label="Abstrak penelitian" hint="Maks. 250 kata." optional />

    Props: name · id · label · value (or slot) · rows (4) · hint · error · optional · hide-label
--}}
@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'value' => null,
    'rows' => 4,
    'hint' => null,
    'error' => null,
    'optional' => false,
    'hideLabel' => false,
])
@php
    $wireModel = $attributes->wire('model')->value();
    $errorKey = $wireModel ?: ($name !== null ? rtrim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null);
    $id ??= 'f-'.($errorKey !== null ? str_replace('.', '-', $errorKey) : \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)));
    $error ??= $errorKey !== null ? ($errors ?? null)?->first($errorKey) : null;
    $error = $error ?: null;
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $text = ($wireModel || $name === null) ? $value : (request()->hasSession() ? old($errorKey, $value) : $value);
    // Computed here and printed once: an @if inside <textarea> gets Livewire's <!--[if BLOCK]> markers, which a
    // textarea shows as text (W2-C finding).
    $content = $text ?? $slot;
@endphp
<x-sth.field :for="$id" :label="$label" :hint="$hint" :error="$error" :optional="$optional" :hide-label="$hideLabel" class="{{ $attributes->get('class') }}">
    <textarea
        id="{{ $id }}"
        @if ($name !== null) name="{{ $name }}" @endif
        rows="{{ $rows }}"
        class="input"
        @if ($error) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except('class') }}
    >{{ $content }}</textarea>
</x-sth.field>
