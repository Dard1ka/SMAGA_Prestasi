{{--
    Label + control + hint + error wrapper. input/select/textarea/file-drop use it; use it directly for
    custom controls. Give the control id="{for}", aria-describedby="{for}-hint {for}-error" and
    aria-invalid when there is an error.

    <x-sth.field for="nama" label="Nama lomba" hint="Tulis tanpa singkatan." :error="$errors->first('nama')">
        <input id="nama" class="input" …>
    </x-sth.field>

    Props: for · label · hint · error · optional (appends "(opsional)") · hide-label (sr-only label)
--}}
@props(['for' => null, 'label' => null, 'hint' => null, 'error' => null, 'optional' => false, 'hideLabel' => false])
<div {{ $attributes->class('flex min-w-0 flex-col gap-2') }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif @class(['label', 'sr-only' => $hideLabel])>
            {{ $label }}@if ($optional) <span class="font-medium text-muted">(opsional)</span>@endif
        </label>
    @endif
    {{ $slot }}
    @if ($hint)
        <p id="{{ $for }}-hint" class="field-hint">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $for }}-error" class="field-error"><x-sth.icon name="alert" :size="16" />{{ $error }}</p>
    @endif
</div>
