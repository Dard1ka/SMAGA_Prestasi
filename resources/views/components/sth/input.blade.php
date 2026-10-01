{{--
    Text-like input with label, hint and validation error (read from $errors by wire:model or name).
    `class` goes to the wrapper (grid placement); every other attribute goes to the <input>.

    <x-sth.input name="nama" label="Nama lomba" required />
    <x-sth.input wire:model.blur="form.website" type="url" label="Website resmi" placeholder="https://…" optional />
    <x-sth.input name="login" label="NIS / NISN" inputmode="numeric" autocomplete="username" size="lg" />
    <x-sth.input name="password" type="password" label="Kata sandi" revealable autocomplete="current-password" />
    <x-sth.input type="search" name="q" label="Cari lomba" hide-label icon="search" placeholder="Cari lomba" />

    Props: name · id (default from name) · label · type (text) · value · hint · error (overrides $errors)
           icon (leading) · revealable (password show/hide button, JS) · optional · hide-label · size md | lg (48px)
--}}
@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'type' => 'text',
    'value' => null,
    'hint' => null,
    'error' => null,
    'icon' => null,
    'revealable' => false,
    'optional' => false,
    'hideLabel' => false,
    'size' => 'md',
])
@php
    $wireModel = $attributes->wire('model')->value();
    $errorKey = $wireModel ?: ($name !== null ? rtrim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null);
    $id ??= 'f-'.($errorKey !== null ? str_replace('.', '-', $errorKey) : \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)));
    $error ??= $errorKey !== null ? ($errors ?? null)?->first($errorKey) : null;
    $error = $error ?: null;
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    $currentValue = ($type === 'password' || $wireModel || $name === null) ? $value : (request()->hasSession() ? old($errorKey, $value) : $value);
@endphp
<x-sth.field :for="$id" :label="$label" :hint="$hint" :error="$error" :optional="$optional" :hide-label="$hideLabel" class="{{ $attributes->get('class') }}">
    <div class="relative">
        @if ($icon)
            <x-sth.icon :name="$icon" class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-placeholder" />
        @endif
        <input
            id="{{ $id }}"
            type="{{ $type }}"
            @if ($name !== null) name="{{ $name }}" @endif
            @if ($currentValue !== null && $type !== 'password') value="{{ $currentValue }}" @endif
            @class(['input', 'h-12' => $size === 'lg', 'pl-11' => $icon, 'pr-14' => $revealable])
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except('class') }}
        >
        @if ($revealable)
            <button type="button" class="icon-btn icon-btn-ghost absolute top-1/2 right-1 -translate-y-1/2" aria-label="Tampilkan kata sandi" aria-pressed="false" aria-controls="{{ $id }}" data-sth-reveal="{{ $id }}">
                <x-sth.icon name="eye" data-sth-reveal-off />
                <x-sth.icon name="eye-off" data-sth-reveal-on hidden />
            </button>
        @endif
    </div>
</x-sth.field>
