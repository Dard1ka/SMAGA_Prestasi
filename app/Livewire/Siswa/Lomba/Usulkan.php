<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\Lomba;

use App\Actions\Competitions\SubmitCompetition;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\PointFormatter;
use App\Livewire\Forms\CompetitionFields;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Siswa · Usulkan lomba (`siswa.lomba.usulkan`, D-145): the whole lomba on one page, with the same fields and layout as
 * the Kelola lomba drawer (CompetitionFields). Step 1 "Data lomba" saves through SubmitCompetition, which stores it in
 * `menunggu_verifikasi` with the weight computed from the jenis penyelenggara (D-146) and a required juknis (D-147).
 * Step 2 shows the registration panel of that lomba (Siswa\Pendaftaran\Form); "Nanti saja" goes to its Detail lomba.
 * Replaces the old "Usulkan lomba" sheet (Lomba\UsulanForm).
 */
#[Title('Usulkan lomba')]
#[Layout('layouts::app', ['subtitle' => 'Kesiswaan memeriksa setiap usulan sebelum masuk katalog.'])]
final class Usulkan extends Component
{
    use WithFileUploads;

    /** Sends that reach SubmitCompetition per hour (the page's own checks do not count); the Action keeps 5 a day. */
    private const TRIES_PER_HOUR = 10;

    public CompetitionFields $form;

    /** The lomba saved in step 1; set only by simpan(). */
    #[Locked]
    public ?string $competitionId = null;

    public function mount(): void
    {
        Gate::authorize('create', Competition::class);
    }

    /**
     * Kategori bobot follows the jenis penyelenggara and the Puspresnas tick (shown at once, D-146); a chosen file is
     * checked at once, so a wrong type is named before "Simpan".
     */
    public function updatedForm(mixed $value, string $key): void
    {
        if (in_array($key, ['organizer_type', 'puspresnas'], true)) {
            $this->form->applyOrganizerWeight();
        }

        if (in_array($key, ['juknis', 'cover'], true)) {
            $this->form->validateOnly($key);
        }
    }

    public function simpan(SubmitCompetition $submit): void
    {
        Gate::authorize('create', Competition::class);

        // A saved lomba moves on to its registration; a second click never sends it twice.
        if ($this->competitionId !== null) {
            return;
        }

        $this->resetErrorBag();

        try {
            $this->form->validate();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            $this->invalid();

            return;
        }

        // Livewire hands text over as typed or pasted (no TrimStrings). Clean it first, so the page checks exactly what
        // SubmitCompetition stores and the fields show it (lead's mail test, 2026-10-09).
        $this->form->cleanText();

        $key = 'usulan:'.$this->actor()->getKey();

        if (RateLimiter::tooManyAttempts($key, self::TRIES_PER_HOUR)) {
            $this->addError('form.name', 'Terlalu sering. Coba lagi beberapa menit lagi.');
            $this->invalid();

            return;
        }

        RateLimiter::hit($key, 3600);

        try {
            $competition = $submit->handle($this->actor(), $this->form->toAttributes(), $this->form->juknis, $this->form->cover);
        } catch (ValidationException $e) {
            foreach ($this->form->addActionErrors($e) as $key => $messages) {
                $this->addError($key, $messages[0] ?? 'Periksa isian ini.');
            }

            $this->invalid();

            return;
        }

        $this->competitionId = $competition->getKey();
        $this->form->reset(['juknis', 'cover']);
        $this->dispatch('sth-toast', message: 'Usulan lomba tersimpan dan menunggu verifikasi Kesiswaan.', tone: 'success');
    }

    public function render(PointCalculator $calculator, PointFormatter $formatter): View
    {
        return view('livewire.siswa.lomba.usulkan', [
            ...$this->form->presentation($calculator, $formatter),
            'staff' => false,
            'competition' => null,
        ]);
    }

    /** The page is long and the button sits at its end: app.js brings the first marked field into view. */
    private function invalid(): void
    {
        $this->dispatch('sth-form-invalid', form: 'usulkan');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
