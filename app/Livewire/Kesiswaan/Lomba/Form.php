<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan\Lomba;

use App\Actions\Competitions\CompetitionChange;
use App\Actions\Competitions\CompetitionDates;
use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Actions\Competitions\VerifyCompetition;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\PointFormatter;
use App\Enums\CompetitionStatus;
use App\Enums\EventStatus;
use App\Enums\RoleName;
use App\Livewire\Forms\CompetitionFields;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Drawer form of Kelola lomba (child of Lomba\Index): "Tambah lomba" (Admin only since D-145; CreateCompetition →
 * antrean, D-27), "Ubah lomba" (UpdateCompetition) and "Periksa usulan" (UpdateCompetition + VerifyCompetition). The
 * fields are the shared Form Object CompetitionFields, drawn by the same partial as Siswa › Usulkan lomba (D-147). All
 * rules live in the Actions; this component only maps their errors onto the `form.*` fields. The rumus preview
 * ("Perkiraan poin jika Juara I") comes from App\Domain\Points with the active weight version.
 *
 * D-146: picking the jenis penyelenggara or the Puspresnas tick fills kategori bobot and status ajang from the map
 * (OrganizerWeight); Kesiswaan may still choose others, shown as "Diubah dari hasil peta." and audited by
 * VerifyCompetition (`weight_override`).
 *
 * "Ubah lomba" of a published lomba (lead, 2026-10-10, D-126): UpdateCompetition tells the registered students and
 * their guru pembimbing when a fact they rely on changes. The drawer says so beforehand (the hint at the top, when
 * students are registered) and the toast names who was told only when somebody was (CompetitionChange).
 */
class Form extends Component
{
    use WithFileUploads;

    public const DIALOG = 'lomba-form';

    #[Locked]
    public ?string $competitionId = null;

    /** create | edit | verify */
    #[Locked]
    public string $formMode = 'create';

    public CompetitionFields $form;

    /**
     * "Tanggal lomba ini sudah lewat. Tetap publikasikan" (M2-B, Q1-04): the tick "Simpan & publikasikan"
     * needs when the registration deadline or the final date has passed. Never needed for "Simpan".
     */
    public bool $confirmPast = false;

    public function mount(): void
    {
        $this->authorize('manage', Competition::class);
    }

    #[On('lomba-form-open')]
    public function open(?string $id = null): void
    {
        $this->authorize('manage', Competition::class);
        $this->resetErrorBag();
        $this->form->reset();
        $this->reset('confirmPast');

        if ($id === null) {
            // "Tambah lomba" is Admin only (D-145): Kesiswaan checks and corrects, it never adds a lomba.
            $this->authorize('create', Competition::class);
            $this->competitionId = null;
            $this->formMode = 'create';
        } else {
            $competition = $this->competition($id);

            if ($this->changedElsewhere($competition, false)) {
                return;
            }

            $this->authorize('update', $competition);

            $this->competitionId = $competition->getKey();
            $this->formMode = $competition->status === CompetitionStatus::MenungguVerifikasi ? 'verify' : 'edit';
            $this->form->fillFrom($competition);
        }

        $this->dispatch('sth-modal-open', id: self::DIALOG);
    }

    /**
     * Kategori bobot follows the jenis penyelenggara and the Puspresnas tick (D-146); a status ajang chosen by hand
     * moves the tick with it. A chosen file is checked at once.
     */
    public function updatedForm(mixed $value, string $key): void
    {
        if (in_array($key, ['organizer_type', 'puspresnas'], true)) {
            $this->form->applyOrganizerWeight();
        }

        if ($key === 'event_status') {
            $this->form->puspresnas = $this->form->event_status === EventStatus::Puspresnas->value;
        }

        if (in_array($key, ['juknis', 'cover'], true)) {
            $this->form->validateOnly($key);
        }
    }

    public function save(CreateCompetition $create, UpdateCompetition $update): void
    {
        $this->resetErrorBag();

        if (! $this->validateForm()) {
            return;
        }

        try {
            if ($this->competitionId === null) {
                $create->handle($this->actor(), $this->form->toAttributes(), $this->form->juknis, $this->form->cover);
                $message = 'Lomba tersimpan dan masuk antrean verifikasi.';
            } else {
                $competition = $this->competition($this->competitionId);

                // A drawer still in "Periksa lomba" expects the lomba in the queue: once a colleague published it,
                // this save could tell registered students (D-126), so it stops once like "Simpan & publikasikan".
                if ($this->changedElsewhere($competition, $this->formMode === 'verify')) {
                    return;
                }

                // A changed fact of a published lomba is told to the students registered for it and their guru
                // pembimbing (UpdateCompetition). The toast names them only when somebody was told.
                $factsBefore = CompetitionChange::snapshot($competition);
                $this->updateWith($update, $competition);
                $message = self::savedMessage(CompetitionChange::summary($factsBefore, $competition, $this->actor()));
            }
        } catch (ValidationException $e) {
            $this->mapErrors($e);

            return;
        }

        $this->finish($message);
    }

    public function saveAndPublish(UpdateCompetition $update, VerifyCompetition $verify): void
    {
        $this->resetErrorBag();

        if ($this->competitionId === null || $this->formMode !== 'verify' || ! $this->validateForm()) {
            return;
        }

        try {
            $competition = $this->competition($this->competitionId);

            if ($this->changedElsewhere($competition, true)) {
                return;
            }

            $this->authorize('verify', $competition);
            $this->updateWith($update, $competition);
            // Without the tick VerifyCompetition refuses past dates under CompetitionDates::PAST_KEY; the
            // data above is saved either way, as with missing fields.
            $verify->handle($this->actor(), $competition->refresh(), CompetitionStatus::Dipublikasikan, null, $this->confirmPast);
        } catch (ValidationException $e) {
            $this->mapErrors($e);
            $this->form->reset(['juknis', 'cover']);

            return;
        }

        $this->finish('Lomba dipublikasikan ke katalog.');
    }

    public function render(PointCalculator $calculator, PointFormatter $formatter): View
    {
        $competition = $this->competitionId !== null ? Competition::query()->with(['juknisFile', 'coverFile', 'submitter'])->find($this->competitionId) : null;
        [$mentors, $mentorHint] = $this->mentorOptions($competition);
        $presentation = $this->form->presentation($calculator, $formatter);
        $presentation['options']['mentors'] = $mentors;

        return view('livewire.kesiswaan.lomba.form', [
            ...$presentation,
            'staff' => true,
            'title' => match ($this->formMode) {
                'edit' => 'Ubah lomba',
                'verify' => 'Periksa lomba',
                default => 'Tambah lomba',
            },
            'competition' => $competition,
            // "Ubah lomba" of a published lomba says beforehand who a changed fact is told to (0 = no hint).
            'attachedStudents' => $this->formMode === 'edit' && $competition !== null ? CompetitionChange::attachedStudents($competition) : 0,
            'submitterName' => $competition?->submitter?->name,
            'juknisUrl' => $competition?->juknisFile !== null && Route::has('files.show') ? route('files.show', $competition->juknisFile) : null,
            // Only publishing asks about past dates, so only the "Periksa lomba" drawer shows the tick.
            'pastDates' => $this->formMode === 'verify'
                ? array_values(CompetitionDates::past($this->form->registration_closes_at, $this->form->final_on))
                : [],
            'mentorHint' => $mentorHint,
        ]);
    }

    private function updateWith(UpdateCompetition $update, Competition $competition): void
    {
        $update->handle($this->actor(), $competition, $this->form->toAttributes(), $this->form->juknis, $this->form->cover, $this->form->remove_cover);
    }

    /**
     * The active gurus, plus the lomba's current default guru when that account is no longer active (QA10): the
     * select then shows who is recorded instead of "Belum ditentukan" while the old id stays in the form, and it can
     * be cleared. UpdateCompetition keeps such an unchanged guru; a new choice must be active.
     *
     * @return array{0: array<int|string, string>, 1: ?string} options, hint under the select
     */
    private function mentorOptions(?Competition $competition): array
    {
        $mentors = User::query()->role(RoleName::Guru->value)->canLogIn()->orderBy('name')->pluck('name', 'id')->all();
        $kept = $competition?->default_mentor_id;

        if ($kept === null || array_key_exists($kept, $mentors)) {
            return [$mentors, null];
        }

        $name = (string) (User::withTrashed()->find($kept)?->name ?? 'Guru');
        $mentors[$kept] = $name.' (tidak aktif)';
        $hint = (int) ($this->form->default_mentor_id ?? 0) === $kept
            ? 'Guru pembimbing yang tercatat, '.$name.', tidak aktif lagi. Pilih guru lain atau kosongkan.'
            : null;

        return [$mentors, $hint];
    }

    /** "Durasi lomba" and the two uploads (CompetitionFields::rules()); every other rule lives in the Actions. */
    private function validateForm(): bool
    {
        try {
            $this->form->validate();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            $this->dispatch('sth-form-invalid', form: self::DIALOG);

            return false;
        }

        return true;
    }

    private function mapErrors(ValidationException $e): void
    {
        $this->resetErrorBag();

        foreach ($this->form->addActionErrors($e) as $key => $messages) {
            // The question about past dates lands on its tick box (the view draws it and lists the dates).
            if ($key === CompetitionDates::PAST_KEY) {
                $this->addError('confirmPast', 'Centang dulu kalau lomba ini tetap dipublikasikan.');

                continue;
            }

            // decision, status: the summary alert at the top of the drawer.
            $this->addError($key, $messages[0] ?? 'Periksa isian ini.');
        }

        // The alert and the marked fields sit in the scrolling drawer body, usually well above the footer
        // the user just clicked, so ask the browser to bring the first one into view.
        $this->dispatch('sth-form-invalid', form: self::DIALOG);
    }

    /**
     * Toast of "Simpan perubahan". The plain sentence when nobody was told; else who was told, and that it was the
     * bell only when the change reached more people than CompetitionChange::MAIL_LIMIT.
     *
     * @param  array{students: int, mentors: int, mail: bool}|null  $told  CompetitionChange::summary()
     */
    private static function savedMessage(?array $told): string
    {
        $saved = 'Perubahan lomba tersimpan.';

        if ($told === null) {
            return $saved;
        }

        $who = array_filter([
            $told['students'] > 0 ? number_format($told['students'], 0, ',', '.').' siswa' : null,
            $told['mentors'] > 0 ? number_format($told['mentors'], 0, ',', '.').' guru pembimbing' : null,
        ]);

        return $saved.' '.implode(' dan ', $who).' diberi tahu'.($told['mail'] ? '.' : ' lewat notifikasi di aplikasi, tanpa email.');
    }

    private function finish(string $message): void
    {
        $this->form->reset(['juknis', 'cover', 'remove_cover']);
        $this->reset('confirmPast');
        $this->dispatch('sth-modal-close', id: self::DIALOG);
        $this->dispatch('lomba-saved');
        $this->dispatch('sth-toast', message: $message, tone: 'success');
    }

    /**
     * Another Kesiswaan or Admin account published, rejected or closed this lomba while the drawer was open (QA9):
     * UpdateCompetition (policy `update`) and publishing (policy `verify`) refused it with a full-screen 403. A lomba
     * that can no longer be edited closes the drawer with the reason; one that was published while this drawer was
     * about to publish it keeps what was typed and turns into "Ubah lomba", so "Simpan" still saves the change. The
     * screen permission is checked first; the policies still decide every other case.
     *
     * `$expectsQueued` = the caller acts on a lomba it believes is still in the queue: "Simpan & publikasikan", and
     * since D-126 also the plain "Simpan" of a "Periksa lomba" drawer, because a save on a published lomba can tell
     * its registered students and their guru. The first click then saves and sends nothing; the drawer shows the
     * hint of "Ubah lomba" and a second click saves. open() passes false: there `formMode` is the last drawer's.
     */
    private function changedElsewhere(Competition $competition, bool $expectsQueued): bool
    {
        $this->authorize('manage', Competition::class);

        $editable = in_array($competition->status, [CompetitionStatus::MenungguVerifikasi, CompetitionStatus::Dipublikasikan], true);

        if ($editable && (! $expectsQueued || $competition->status === CompetitionStatus::MenungguVerifikasi)) {
            return false;
        }

        if ($editable) {
            $this->formMode = 'edit';
            $this->reset('confirmPast');
            $this->dispatch('sth-toast', message: 'Lomba ini sudah dipublikasikan. Perubahan belum tersimpan, periksa lalu simpan.', tone: 'warning');
        } else {
            $this->form->reset(['juknis', 'cover', 'remove_cover']);
            $this->reset('confirmPast');
            $this->dispatch('sth-modal-close', id: self::DIALOG);
            $this->dispatch('sth-toast', message: ($competition->status === CompetitionStatus::Ditolak
                ? 'Lomba ini sudah ditolak.'
                : 'Lomba ini sudah ditutup.').' Daftar sudah diperbarui.', tone: 'warning');
        }

        // The list behind the drawer draws the card as it is now.
        $this->dispatch('lomba-saved');

        return true;
    }

    private function competition(string $id): Competition
    {
        $competition = Competition::query()->find($id);

        abort_if($competition === null, 404);

        return $competition;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
