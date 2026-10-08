<?php

declare(strict_types=1);

namespace App\Livewire\Lomba;

use App\Actions\Competitions\SubmitCompetition;
use App\Domain\Files\StoreUpload;
use App\Enums\FilePurpose;
use App\Models\Competition;
use App\Rules\ExternalUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Usulkan lomba" sheet (board Katalog-Lomba; for guru `guru.lomba.index` named "Upload info lomba"). Renders its own
 * <dialog id="usul">; open it with any `data-sth-open="usul"` button on the page.
 * Calls SubmitCompetition (menunggu_verifikasi, D-27; 5/day; brochure via StoreUpload, private).
 * The official link is validated (https, no IP) and never fetched (SECURITY §3m-1).
 */
class UsulanForm extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $organizer_name = '';

    public string $website_url = '';

    public string $description = '';

    /** @var TemporaryUploadedFile|null */
    public $brosur = null;

    public bool $sent = false;

    public string $sentName = '';

    /**
     * Guru › Lomba opens this sheet with the button "Upload info lomba", so the sheet uses those words
     * (`<livewire:lomba.usulan-form :upload="true" />`). Only the wording differs; fields and rules are the same.
     */
    #[Locked]
    public bool $upload = false;

    public function mount(): void
    {
        Gate::authorize('create', Competition::class);
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        $upload = StoreUpload::rulesFor(FilePurpose::Juknis);

        return [
            'name' => ['required', 'string', 'min:3', 'max:200'],
            // Kesiswaan verifies a proposal against the organizer, the official link and the invitation,
            // so a proposal without them cannot be decided. Only the note stays optional.
            'organizer_name' => ['required', 'string', 'max:200'],
            'website_url' => ['required', 'string', new ExternalUrl],
            'description' => ['nullable', 'string', 'max:1000'],
            'brosur' => [
                'required', 'file', 'max:'.$upload->maxKilobytes(),
                'extensions:'.implode(',', $upload->extensions()),
                'mimetypes:'.implode(',', $upload->mimeTypes()),
            ],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'name.required' => 'Isi nama lomba dulu.',
            'name.min' => 'Nama lomba minimal 3 karakter.',
            'name.max' => 'Nama lomba maks. 200 karakter.',
            'organizer_name.required' => 'Isi nama penyelenggara dulu.',
            'organizer_name.max' => 'Nama penyelenggara maks. 200 karakter.',
            'website_url.required' => 'Isi link resmi lomba dulu.',
            'brosur.required' => 'Upload brosur atau juknis dulu.',
            'description.max' => 'Catatan maks. 1000 karakter.',
            'brosur.max' => 'Ukuran file maksimal 5 MB.',
            'brosur.extensions' => 'Pakai file PDF, JPG, PNG, atau WEBP.',
            'brosur.mimetypes' => 'Isi file tidak sesuai jenis yang diizinkan.',
            'brosur.file' => 'File gagal di-upload. Coba lagi.',
        ];
    }

    public function kirim(SubmitCompetition $submit): void
    {
        Gate::authorize('create', Competition::class);

        try {
            $this->validate();
        } catch (ValidationException $e) {
            // The sheet scrolls inside .dlg-body and the send button sits in its sticky footer, so a
            // field error can land out of sight. Let app.js jump to the first one.
            $this->dispatch('sth-form-invalid', form: 'usul');

            throw $e;
        }

        $key = 'usulan:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['name' => 'Terlalu sering. Coba lagi beberapa menit lagi.']);
        }

        RateLimiter::hit($key, 3600);

        $competition = $submit->handle(auth()->user(), [
            'name' => $this->name,
            'organizer_name' => $this->organizer_name,
            'website_url' => $this->website_url,
            'description' => $this->description,
        ], $this->brosur);

        $this->sentName = $competition->name;
        $this->sent = true;
        $this->reset('name', 'organizer_name', 'website_url', 'description', 'brosur');
        $this->resetValidation();
        $this->dispatch('usulan-terkirim');
    }

    public function ulang(): void
    {
        // Everything but the wording the page chose.
        $this->resetExcept('upload');
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.lomba.usulan-form');
    }
}
