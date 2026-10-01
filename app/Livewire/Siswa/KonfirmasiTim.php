<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\DeclineMembership;
use App\Enums\MemberRole;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Models\Registration;
use App\Models\RegistrationMember;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Siswa · Konfirmasi ikut tim (link from the TeamInvitation notification and Beranda "Perlu tindakan").
 * Only members of the registration may open it (RegistrationPolicy::view, else 404); only the invitee
 * may answer (RegistrationPolicy::respond → ConfirmMembership / DeclineMembership, SECURITY §3m-3).
 */
#[Layout('layouts::app')]
#[Title('Undangan tim')]
class KonfirmasiTim extends Component
{
    #[Locked]
    public string $registrationId = '';

    public function mount(Registration $registration): void
    {
        abort_unless($this->isMember($registration), 404);

        $this->registrationId = $registration->getKey();
    }

    public function ikut(ConfirmMembership $confirm): void
    {
        $registration = $this->registration();
        if (Gate::denies('respond', $registration)) {
            $this->addError('registration', 'Undangan ini sudah tidak berlaku.');

            return;
        }

        $confirm->handle(auth()->user(), $registration);

        session()->flash('toast', 'Kamu ikut tim '.$registration->competition->name.'.');
        $this->redirectRoute('siswa.undangan.show', ['registration' => $registration->getKey()]);
    }

    public function tolak(DeclineMembership $decline): void
    {
        $registration = $this->registration();
        if (Gate::denies('respond', $registration)) {
            $this->addError('registration', 'Undangan ini sudah tidak berlaku.');

            return;
        }

        $decline->handle(auth()->user(), $registration);

        session()->flash('toast', ['tone' => 'info', 'message' => 'Undangan ditolak. Ketua tim sudah diberi tahu.']);
        $this->redirectRoute('siswa.beranda');
    }

    public function render(): View
    {
        $registration = $this->registration();
        $me = auth()->user()->loadMissing('student')->getRelation('student');
        $mine = $registration->members->firstWhere('student_id', $me?->getKey());
        $competition = $registration->competition;
        $mentor = $registration->mentor;

        return view('livewire.siswa.konfirmasi-tim', [
            'registration' => $registration,
            'competition' => $competition,
            'canRespond' => Gate::allows('respond', $registration),
            'mine' => $mine,
            'members' => $registration->members
                ->sortBy(fn (RegistrationMember $m): string => ($m->role === MemberRole::Ketua ? '0' : '1').$m->student?->user?->name)
                ->map(fn (RegistrationMember $m): array => [
                    'id' => $m->getKey(),
                    'name' => (string) $m->student?->user?->name,
                    'kelas' => (string) $m->student?->classroom?->name,
                    'role' => $m->role?->label(),
                    'me' => $m->getKey() === $mine?->getKey(),
                    'state' => match (true) {
                        $m->declined_at !== null => ['Menolak', 'red'],
                        $m->confirmed_at !== null => ['Sudah konfirmasi', 'green'],
                        default => ['Menunggu konfirmasi', 'gray'],
                    },
                ])->values()->all(),
            'mentorName' => $mentor === null ? '–' : trim(($mentor->staffProfile?->honorific ? $mentor->staffProfile->honorific.' ' : '').$mentor->name),
            'facts' => [
                ['icon' => 'clock', 'label' => 'Batas daftar', 'value' => LombaPresenter::date($competition->registration_closes_at)],
                ['icon' => 'calendar', 'label' => 'Tanggal lomba', 'value' => LombaPresenter::tanggal($competition)],
                ['icon' => 'trophy', 'label' => 'Tingkat', 'value' => LombaPresenter::tingkat($competition)],
                ['icon' => 'pin', 'label' => 'Pelaksanaan', 'value' => LombaPresenter::tempat($competition)],
            ],
            'detailUrl' => Gate::allows('view', $competition) ? route('siswa.lomba.show', $competition) : null,
            'lombaSayaUrl' => Route::has('siswa.lomba-saya.show') ? route('siswa.lomba-saya.show', $registration) : null,
        ]);
    }

    private function registration(): Registration
    {
        $registration = Registration::query()
            ->with(['competition', 'mentor.staffProfile', 'members.student.user:id,name', 'members.student.classroom:id,name'])
            ->findOrFail($this->registrationId);

        abort_unless($this->isMember($registration), 404);

        return $registration;
    }

    /** Students only reach this page for registrations they are part of (staff use their own screens). */
    private function isMember(Registration $registration): bool
    {
        $student = auth()->user()?->loadMissing('student')->getRelation('student');

        return $student !== null
            && Gate::allows('view', $registration)
            && $registration->members()->where('student_id', $student->getKey())->exists();
    }
}
