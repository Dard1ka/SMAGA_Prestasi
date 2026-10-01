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
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * "Usulkan lomba" sheet (board Katalog-Lomba; also for guru `guru.lomba.index`). Renders its own
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
            'organizer_name' => ['nullable', 'string', 'max:200'],
            'website_url' => ['nullable', 'string', new ExternalUrl],
            'description' => ['nullable', 'string', 'max:1000'],
            'brosur' => [
                'nullable', 'file', 'max:'.$upload->maxKilobytes(),
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
            'organizer_name.max' => 'Nama penyelenggara maks. 200 karakter.',
            'description.max' => 'Catatan maks. 1000 karakter.',
            'brosur.max' => 'Ukuran file maksimal 5 MB.',
            'brosur.extensions' => 'Pakai file PDF, JPG, PNG, atau WEBP.',
            'brosur.mimetypes' => 'Isi file tidak sesuai jenis yang diizinkan.',
            'brosur.file' => 'File gagal diunggah. Coba lagi.',
        ];
    }

    public function kirim(SubmitCompetition $submit): void
    {
        Gate::authorize('create', Competition::class);

        $this->validate();

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
        $this->reset();
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.lomba.usulan-form');
    }
}
