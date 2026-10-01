{{--
    Pick buttons (.pick / .pick-on): a fieldset of real radios (or checkboxes with `multiple`) styled as
    tiles. Arrow keys, focus ring and checked state are native; selected style comes from :has(:checked).

    <x-sth.radio-pick name="tingkat" label="Tingkat" value="nasional"
        :options="['kota' => 'Kota/Kab', 'provinsi' => 'Provinsi', 'nasional' => 'Nasional', 'internasional' => 'Internasional']" />
    <x-sth.radio-pick wire:model.live="form.weight_category" label="Kategori penyelenggara" :columns="5"
        :options="['A' => ['label' => 'A', 'sub' => '1,00'], 'B' => ['label' => 'B', 'sub' => '0,80'], …]" />
    <x-sth.radio-pick name="bidang[]" multiple label="Minat bidang"
        :options="['sains' => ['label' => 'Sains', 'icon' => 'sains'], …]" :value="['sains', 'riset']" />

    Props: name · label (legend) · options (value => label | value => [label, sub, icon, disabled])
           value (string, or array with multiple) · multiple · columns (2–6, grid; default wraps)
           size md (44px) | sm (40px) · hint · error · hide-label
--}}
@props([
    'name' => null,
    'label' => null,
    'options' => [],
    'value' => null,
    'multiple' => false,
    'columns' => null,
    'size' => 'md',
    'hint' => null,
    'error' => null,
    'hideLabel' => false,
])
@php
    $wire = $attributes->whereStartsWith('wire:model');
    $wireModel = $attributes->wire('model')->value();
    $baseName = $name !== null ? rtrim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null;
    $errorKey = $wireModel ?: $baseName;
    $groupId = 'f-'.($errorKey !== null ? str_replace('.', '-', $errorKey) : \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)));
    $error ??= $errorKey !== null ? ($errors ?? null)?->first($errorKey) : null;
    $error = $error ?: null;
    $describedBy = trim(($hint ? $groupId.'-hint ' : '').($error ? $groupId.'-error' : ''));
    $inputName = $name !== null ? ($multiple && ! str_ends_with($name, '[]') ? $name.'[]' : $name) : null;
    $current = ($wireModel || $name === null) ? $value : (request()->hasSession() ? old($baseName, $value) : $value);
    $current = array_map(fn ($v) => $v instanceof \BackedEnum ? (string) $v->value : (string) $v, (array) $current);
    $grid = [
        2 => 'grid grid-cols-2',
        3 => 'grid grid-cols-3',
        4 => 'grid grid-cols-2 sm:grid-cols-4',
        5 => 'grid grid-cols-3 sm:grid-cols-5',
        6 => 'grid grid-cols-3 sm:grid-cols-6',
    ][(int) $columns] ?? 'flex flex-wrap';
@endphp
<fieldset {{ $attributes->whereDoesntStartWith('wire:model')->class('min-w-0') }} @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif>
    @if ($label)
        <legend @class(['label mb-2', 'sr-only' => $hideLabel])>{{ $label }}</legend>
    @endif
    <div class="{{ $grid }} gap-2">
        @foreach ($options as $optionValue => $option)
            @php
                $option = is_array($option) ? $option : ['label' => $option];
                $optionId = $groupId.'-'.\Illuminate\Support\Str::slug((string) $optionValue);
            @endphp
            <label for="{{ $optionId }}" @class(['pick', 'min-h-10 text-[13.5px]' => $size === 'sm', 'flex-col gap-0.5 py-1.5' => isset($option['sub'])])>
                <input
                    type="{{ $multiple ? 'checkbox' : 'radio' }}"
                    id="{{ $optionId }}"
                    @if ($inputName !== null) name="{{ $inputName }}" @endif
                    value="{{ $optionValue }}"
                    class="sr-only"
                    @checked(in_array((string) $optionValue, $current, true))
                    @disabled($option['disabled'] ?? false)
                    @if ($error) aria-invalid="true" @endif
                    {{ $wire }}
                >
                @if (! empty($option['icon']))
                    <x-sth.icon :name="$option['icon']" :size="18" />
                @endif
                <span @class(['display text-[15px] leading-4 font-extrabold' => isset($option['sub'])])>{{ $option['label'] }}</span>
                @isset($option['sub'])
                    <span class="pick-sub leading-[14px]">{{ $option['sub'] }}</span>
                @endisset
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
