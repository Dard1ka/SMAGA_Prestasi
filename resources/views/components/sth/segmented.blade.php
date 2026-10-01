{{--
    Segmented control (Individu | Tim). Real radios inside, so it works in plain forms and with wire:model.

    <x-sth.segmented name="peserta" label="Daftar sebagai" value="tim" block
        :options="['individu' => ['label' => 'Individu', 'icon' => 'user'], 'tim' => ['label' => 'Tim', 'icon' => 'users']]" />
    <x-sth.segmented wire:model.live="mode" label="Tampilkan" hide-label variant="solid"
        :options="['siswa' => 'Siswa', 'kelas' => 'Kelas']" />

    For toggles that are not form values, use buttons: <div class="seg"><button class="seg-btn" aria-pressed="true">…

    Props: name · label (legend) · options (value => label | value => [label, icon, disabled]) · value
           variant soft (white active, default) | solid (blue active) · block (full width, equal columns)
           hint · error · hide-label
--}}
@props([
    'name' => null,
    'label' => null,
    'options' => [],
    'value' => null,
    'variant' => 'soft',
    'block' => false,
    'hint' => null,
    'error' => null,
    'hideLabel' => false,
])
@php
    $wire = $attributes->whereStartsWith('wire:model');
    $wireModel = $attributes->wire('model')->value();
    $errorKey = $wireModel ?: $name;
    $groupId = 'f-'.($errorKey !== null ? str_replace('.', '-', $errorKey) : \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)));
    $error ??= $errorKey !== null ? ($errors ?? null)?->first($errorKey) : null;
    $error = $error ?: null;
    $current = ($wireModel || $name === null) ? $value : (request()->hasSession() ? old($name, $value) : $value);
    $current = $current instanceof \BackedEnum ? $current->value : $current;
@endphp
<fieldset {{ $attributes->whereDoesntStartWith('wire:model')->class('min-w-0') }} @if ($hint || $error) aria-describedby="{{ trim(($hint ? $groupId.'-hint ' : '').($error ? $groupId.'-error' : '')) }}" @endif>
    @if ($label)
        <legend @class(['label mb-2', 'sr-only' => $hideLabel])>{{ $label }}</legend>
    @endif
    <div @class(['seg', 'seg-block' => $block, 'seg-solid' => $variant === 'solid'])>
        @foreach ($options as $optionValue => $option)
            @php
                $option = is_array($option) ? $option : ['label' => $option];
                $optionId = $groupId.'-'.\Illuminate\Support\Str::slug((string) $optionValue);
            @endphp
            <label for="{{ $optionId }}" class="seg-btn">
                <input
                    type="radio"
                    id="{{ $optionId }}"
                    @if ($name !== null) name="{{ $name }}" @endif
                    value="{{ $optionValue }}"
                    class="sr-only"
                    @checked($current !== null && (string) $current === (string) $optionValue)
                    @disabled($option['disabled'] ?? false)
                    {{ $wire }}
                >
                @if (! empty($option['icon']))
                    <x-sth.icon :name="$option['icon']" :size="18" />
                @endif
                {{ $option['label'] }}
            </label>
        @endforeach
    </div>
    @if ($hint)
        <p id="{{ $groupId }}-hint" class="field-hint mt-2">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $groupId }}-error" class="field-error mt-2"><x-sth.icon name="alert" :size="16" />{{ $error }}</p>
    @endif
</fieldset>
