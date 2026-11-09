<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Actions\Accounts\UpdateStudentPhone;
use App\Actions\Registrations\MentorChoice;
use App\Actions\Registrations\RespondToRevision;
use App\Domain\Accounts\PhoneNumber;
use App\Enums\RevisionStatus;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\RegistrationMember;
use App\Models\RegistrationRevision;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * SV8, D-199: Siswa › Undangan revisi (link of RevisionInvitation). A revision of an approved team invites the
 * viewer: "Terima undangan" (with the own data and No HP, D-149, as on Undangan tim) or "Tolak" (asks first, D-128: the
 * revision then ends and the ketua is told). Only the invited student opens it (RegistrationRevisionPolicy::view
 * without the deciders' permission, else 404); only that student answers (RespondToRevision re-checks). The page says
 * what became of an invitation that no longer waits: accepted (waiting for the decision), declined, cancelled, approved
 * or rejected.
 */
#[Layout('layouts::app')]
#[Title('Undangan tim')]
class UndanganRevisi extends Component
{
    public const DECLINE_DIALOG = 'tolak-undangan-revisi';

    #[Locked]
    public string $revisionId = '';

    public string $phone = '';

    public function mount(RegistrationRevision $revision): void
    {
        abort_unless(in_array((string) $this->student()?->getKey(), $revision->additions(), true), 404);

        $this->revisionId = (string) $revision->getKey();
        $this->phone = (string) rescue(fn () => $this->student()?->phone, null, false);
    }

    public function terima(RespondToRevision $respond, UpdateStudentPhone $phones): void
    {
        $revision = $this->revision();

        if (Gate::denies('respond', $revision)) {
            $this->addError('revision', RespondToRevision::MSG_NOT_VALID);

            return;
        }

        $this->phone = PhoneNumber::normalize($this->phone);
        $this->validate(
            ['phone' => ['required', 'regex:'.PhoneNumber::PATTERN]],
            ['phone.required' => UpdateStudentPhone::EMPTY, 'phone.regex' => PhoneNumber::INVALID],
        );

        // The own number first (D-149); a refusal below still leaves it saved: it is the student's own.
        $phones->handle($this->user(), $this->student(), $this->phone);

        try {
            $respond->confirm($this->user(), $revision);
        } catch (ValidationException $e) {
            $this->addError('revision', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->dispatch('sth-toast', message: 'Undangan diterima. Tim berubah setelah revisinya disetujui sekolah.', tone: 'success');
    }

    /** The confirm step of "Tolak undangan?". */
    public function tolak(RespondToRevision $respond): void
    {
        $this->dispatch('sth-modal-close', id: self::DECLINE_DIALOG);
        $revision = $this->revision();

        if (Gate::denies('respond', $revision)) {
            $this->addError('revision', RespondToRevision::MSG_NOT_VALID);

            return;
        }

        try {
            $respond->decline($this->user(), $revision);
        } catch (ValidationException $e) {
            $this->addError('revision', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->dispatch('sth-toast', message: 'Undangan ditolak. Ketua tim diberi tahu.', tone: 'info');
    }

    public function render(): View
    {
        $revision = $this->revision();
        $registration = $revision->registration()->with([
            'competition',
            'leader.user:id,name',
            'mentor.staffProfile',
            'members' => fn ($q) => $q->whereNull('declined_at')->whereHas('student'),
            'members.student.user:id,name',
            'members.student.classroom:id,name',
        ])->firstOrFail();
        $me = (string) $this->student()?->getKey();
        $answer = $revision->answerOf($me);
        $removed = Student::query()->withTrashed()->whereKey($revision->removals())->with('user:id,name')->get();
        $mentorTo = $revision->mentorTo();

        return view('livewire.siswa.undangan-revisi', [
            'competition' => $registration->competition,
            'leader' => (string) $registration->leader?->user?->name,
            'canRespond' => Gate::allows('respond', $revision) && $registration->hasListedLeader(),
            'state' => match (true) {
                $revision->status === RevisionStatus::Approved && $answer === 'confirmed' => 'joined',
                $revision->status === RevisionStatus::Rejected => 'rejected',
                $answer === 'declined' => 'declined',
                $revision->status === RevisionStatus::Cancelled => 'cancelled',
                $answer === 'confirmed' => 'waiting',
                default => 'open',
            },
            'members' => RegistrationMember::inTeamOrder($registration->members)->map(fn (RegistrationMember $m): array => [
                'id' => (string) $m->getKey(),
                'name' => (string) $m->student?->user?->name,
                'kelas' => (string) $m->student?->classroom?->name,
                'role' => $m->role?->label(),
            ])->values()->all(),
            'removed' => $removed->map(fn (Student $s): string => (string) $s->user?->name)->values()->all(),
            'mentor' => MentorChoice::name($registration->mentor) ?? '–',
            'newMentor' => $mentorTo !== null ? MentorChoice::name(User::query()->with('staffProfile')->find($mentorTo)) : null,
            'reason' => $revision->reason,
            'peserta' => PendaftaranForm::pesertaRows($this->user(), $this->student()?->loadMissing('classroom')),
            'lombaSayaUrl' => Route::has('siswa.lomba-saya.show') ? route('siswa.lomba-saya.show', $registration) : null,
        ]);
    }

    private function revision(): RegistrationRevision
    {
        $revision = RegistrationRevision::query()->find($this->revisionId);
        abort_if($revision === null || Gate::denies('view', $revision), 404);

        return $revision;
    }

    private function student(): ?Student
    {
        return $this->user()->loadMissing('student')->getRelation('student');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
