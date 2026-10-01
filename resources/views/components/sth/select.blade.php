{{--
    Native <select> with label, chevron, hint and error.

    <x-sth.select name="tingkat" label="Tingkat" placeholder="Pilih tingkat"
                  :options="['kota' => 'Kota/Kab', 'provinsi' => 'Provinsi', 'nasional' => 'Nasional']" />
    <x-sth.select wire:model.live="level" label="Tingkat" :options="\App\Enums\Level::cases()" hide-label />
    <x-sth.select name="guru" label="Guru pembimbing" :options="['Sains' => ['u1' => 'Pak Hendra Kusuma, S.Pd., M.Si.']]" />

    Props: name · id · label · options (value => label | value => [group options] | list of BackedEnum
           with label()) · value (selected) · placeholder (empty first option) · hint · error · optional
           hide-label · size md | sm (40px, toolbars)
--}}
@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'options' => [],
    'value' => null,
    'placeholder' => null,
    'hint' => null,
    'error' => null,
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
    $selected = ($wireModel || $name === null) ? $value : (request()->hasSession() ? old($errorKey, $value) : $value);
    $selected = $selected instanceof \BackedEnum ? $selected->value : $selected;

    $normalize = function ($options): array {
        $out = [];
        foreach ($options as $key => $option) {
            if ($option instanceof \BackedEnum) {
                $out[(string) $option->value] = method_exists($option, 'label') ? $option->label() : $option->name;
            } else {
                $out[$key] = $option;
            }
        }

        return $out;
    };
    $options = $normalize($options);
@endphp
<x-sth.field :for="$id" :label="$label" :hint="$hint" :error="$error" :optional="$optional" :hide-label="$hideLabel" class="{{ $attributes->get('class') }}">
    <div class="relative">
        <select
            id="{{ $id }}"
            @if ($name !== null) name="{{ $name }}" @endif
            @class(['input', 'h-10 text-sm' => $size === 'sm'])
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except('class') }}
        >
            @if ($placeholder !== null)
                <option value="">{{ $placeholder }}</option>
            @endif
            @foreach ($options as $optionValue => $optionLabel)
                @if (is_array($optionLabel))
                    <optgroup label="{{ $optionValue }}">
                        @foreach ($normalize($optionLabel) as $groupValue => $groupLabel)
                            <option value="{{ $groupValue }}" @selected($selected !== null && (string) $selected === (string) $groupValue)>{{ $groupLabel }}</option>
                        @endforeach
                    </optgroup>
                @else
                    <option value="{{ $optionValue }}" @selected($selected !== null && (string) $selected === (string) $optionValue)>{{ $optionLabel }}</option>
                @endif
            @endforeach
            {{ $slot }}
        </select>
        <x-sth.icon name="chevron-down" :size="18" class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-muted" />
    </div>
</x-sth.field>
