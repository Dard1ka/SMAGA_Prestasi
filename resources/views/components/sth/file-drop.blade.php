{{--
    Upload drop zone (.drop). The whole zone is the <label> of a visually hidden <input type="file">,
    so click, keyboard (Tab + Enter/Space) and drag-and-drop (resources/js/app.js) all work. The chosen
    file name replaces the title. Server-side checks stay in App\Domain\Files (allowlist, sniffed MIME,
    10 MB) — `accept` is only a hint.

    <x-sth.file-drop name="sertifikat" label="Sertifikat" accept=".pdf,.jpg,.jpeg,.png" />
    <x-sth.file-drop wire:model="brosur" label="Brosur atau juknis" accept=".pdf" hint="PDF · maks. 10 MB" optional />
    <x-sth.file-drop name="foto[]" multiple label="Foto dokumentasi" accept=".jpg,.jpeg,.png,.webp" icon="image" />

    With wire:model, pass the bound property as :file so the chosen name survives a Livewire re-render:

    <x-sth.file-drop wire:model="brosur" :file="$brosur" title="Pilih file PDF atau gambar" />

    Without it the name is only ever written to the DOM by app.js on `change`, and the next morph paints
    the placeholder back over it -- the upload looks like it was rejected when it actually succeeded.

    Props: name · id · label · title ("Pilih file") · file (chosen upload, or an array of them)
           hint ("PDF, JPG, atau PNG · maks. 10 MB") · accept · multiple · icon (upload) · error · optional
--}}
@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'title' => 'Pilih file atau seret ke sini',
    'file' => null,
    'hint' => 'PDF, JPG, atau PNG · maks. 10 MB',
    'accept' => null,
    'multiple' => false,
    'icon' => 'upload',
    'error' => null,
    'optional' => false,
])
@php
    $wireModel = $attributes->wire('model')->value();
    $errorKey = $wireModel ?: ($name !== null ? rtrim(str_replace(['[]', '[', ']'], ['', '.', ''], $name), '.') : null);
    $id ??= 'f-'.($errorKey !== null ? str_replace('.', '-', $errorKey) : \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)));
    $error ??= $errorKey !== null ? (($errors ?? null)?->first($errorKey) ?: ($errors ?? null)?->first($errorKey.'.*')) : null;
    $error = $error ?: null;
    $describedBy = trim(($hint ? $id.'-hint ' : '').($error ? $id.'-error' : ''));
    // A chosen upload names itself. $placeholder keeps the empty-state wording, which app.js restores
    // through data-empty when the picker is cleared.
    $placeholder = $title;
    $chosen = collect(is_array($file) ? $file : [$file])
        ->filter()
        ->map(fn ($f) => is_object($f) && method_exists($f, 'getClientOriginalName') ? $f->getClientOriginalName() : (string) $f)
        ->filter()
        ->implode(', ');
    $title = $chosen !== '' ? $chosen : $title;
@endphp
<x-sth.field :for="$id" :label="$label" :error="$error" :optional="$optional" class="{{ $attributes->get('class') }}">
    <label for="{{ $id }}" class="drop" data-sth-drop @if ($error) aria-invalid="true" @endif>
        <span class="drop-ic"><x-sth.icon :name="$icon" /></span>
        <span class="flex min-w-0 flex-col gap-0.5">
            <span class="ellip text-md font-bold text-ink" data-sth-file-name data-empty="{{ $placeholder }}">{{ $title }}</span>
            @if ($hint)
                <span id="{{ $id }}-hint" class="text-xs text-muted">{{ $hint }}</span>
            @endif
        </span>
        <input
            type="file"
            id="{{ $id }}"
            @if ($name !== null) name="{{ $name }}" @endif
            @if ($accept) accept="{{ $accept }}" @endif
            @if ($multiple) multiple @endif
            class="sr-only"
            @if ($error) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except('class') }}
        >
    </label>
</x-sth.field>
