{{--
    File preview popup (M3-C): shows a PDF or an image in a dialog instead of downloading it. One dialog per
    page; layouts::app and layouts::mobile render it once (<x-sth.preview />), resources/js/app.js fills it.

    Any link (or button) opens it with three attributes; the link itself stays the download:

        <x-sth.button variant="quiet" size="sm" icon="eye" :href="route('files.show', $file)"
            :data-sth-preview="$previewUrl" :data-sth-preview-type="$previewType"
            :data-sth-preview-name="$file->original_name" aria-haspopup="dialog">Buka</x-sth.button>

        $previewUrl  = App\Http\Controllers\FileController::previewUrl($file)   // files.show + ?tampil=1, or null
        $previewType = App\Http\Controllers\FileController::previewType($file)  // 'pdf' | 'image' | null

    - href                    the download address (what happens without JavaScript, and the dialog's "Download")
    - data-sth-preview        the inline address; null/absent = a normal download link (Blade drops null attributes)
    - data-sth-preview-type   pdf | image; anything else opens the dialog with one sentence and "Download"
    - data-sth-preview-name   the dialog title (file name)
    - on a <button>           give the download address in data-sth-preview-download

    Behaviour: title = file name · body = the viewer (PDF: the browser's own viewer in a same-origin <iframe>,
    image: <img>) · footer = "Tutup" and "Download". A browser without a PDF viewer (many phones), a file that
    cannot be loaded or an unknown type gets one sentence instead of the viewer; "Download" always works.
    Closing (X, Tutup, Esc, click outside) removes the viewer, so the file is unloaded. Links added later by
    Livewire work too (delegated listener). A page without this dialog simply downloads.
    Texts live here (data-msg-*), not in the script.
--}}
<x-sth.modal id="sth-preview" title="Preview file" class="dlg-preview">
    <div class="preview-stage" data-sth-preview-stage
        data-msg-loading="Memuat file…"
        data-msg-frame="Preview"
        data-msg-unsupported="Jenis file ini tidak bisa ditampilkan di sini, download untuk membukanya."
        data-msg-nopdf="Browser ini tidak bisa menampilkan PDF, download untuk membukanya."
        data-msg-error="File tidak bisa ditampilkan, coba download atau muat ulang halaman.">
        <p class="preview-note" data-sth-preview-status role="status"></p>
    </div>
    <x-slot:footer>
        <x-sth.button variant="quiet" data-sth-close>Tutup</x-sth.button>
        <x-sth.button icon="download" href="#" :navigate="false" data-sth-preview-download>Download</x-sth.button>
    </x-slot:footer>
</x-sth.modal>
