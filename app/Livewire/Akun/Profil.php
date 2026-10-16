<?php

declare(strict_types=1);

namespace App\Livewire\Akun;

use App\Actions\Accounts\UpdateStudentProfile;
use App\Domain\Accounts\PhoneNumber;
use App\Domain\Accounts\StudentProfileInput;
use App\Enums\RoleName;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Profil saya › Data diri (`akun.profil`, every role, D-152): the account menu item above Notifikasi. Tabs, see
 * tabs(): "Data diri" (this page), for a student "Talenta saya" (Siswa\ProfilTalenta, D-153) and "Keamanan akun"
 * (Akun\Keamanan, `akun.keamanan`, unchanged).
 *
 * - Student: name, NISN, class and email read-only; birth date and No HP editable (UpdateStudentProfile, the same rules
 *   as the "Data diri" step of the activation). Shown to the owner only: there is no id in the address.
 * - Staff: name, role, username and email, read-only (names and roles are changed by the Admin).
 */
#[Title('Profil saya')]
#[Layout('layouts::app', ['subtitle' => 'Data diri dan keamanan akun.'])]
final class Profil extends Component
{
    public string $birth_date = '';

    public string $phone = '';

    public function mount(): void
    {
        $student = $this->student();

        if ($student !== null) {
            $this->birth_date = (string) $student->birth_date?->format('Y-m-d');
            $this->phone = (string) rescue(fn () => $student->phone, null, false);
        }
    }

    public function save(UpdateStudentProfile $update): void
    {
        $this->resetErrorBag();

        $student = $this->student();

        // Staff have no form here; a forged call gets the same answer as any other ability they lack.
        abort_if($student === null, 403);

        $changed = $update->handle($this->user(), $student, $this->birth_date, $this->phone);

        $this->phone = PhoneNumber::normalize($this->phone);

        $this->dispatch(
            'sth-toast',
            message: $changed === [] ? 'Tidak ada perubahan.' : 'Data diri tersimpan.',
            tone: $changed === [] ? 'info' : 'success',
        );
    }

    public function render(): View
    {
        $user = $this->user();
        $student = $this->student();
        $today = CarbonImmutable::today();

        return view('livewire.akun.profil', [
            'user' => $user,
            'student' => $student,
            'roles' => collect($user->getRoleNames())
                ->map(fn (string $role): string => RoleName::tryFrom($role)?->label() ?? $role)
                ->implode(', '),
            'tabs' => self::tabs($user),
            'birthDateMin' => $today->subYears(StudentProfileInput::MAX_AGE)->format('Y-m-d'),
            'birthDateMax' => $today->subYears(StudentProfileInput::MIN_AGE)->format('Y-m-d'),
        ]);
    }

    /**
     * The tabs of Profil saya, drawn on every one of its pages: Data diri and Keamanan akun (D-152), and for a student
     * Talenta saya in between (D-153; the page Siswa\ProfilTalenta, `siswa.talenta`).
     *
     * @return list<array{key: string, label: string, href: string}>
     */
    public static function tabs(?User $user = null): array
    {
        $tabs = [['key' => 'profil', 'label' => 'Data diri', 'href' => route('akun.profil')]];

        if ($user !== null && $user->hasRoleName(RoleName::Siswa) && Route::has('siswa.talenta')) {
            $tabs[] = ['key' => 'talenta', 'label' => 'Talenta saya', 'href' => route('siswa.talenta')];
        }

        $tabs[] = ['key' => 'keamanan', 'label' => 'Keamanan akun', 'href' => route('akun.keamanan')];

        return $tabs;
    }

    /** The signed-in student's own record, or null for staff (the edit needs the siswa role, StudentPolicy). */
    private function student(): ?Student
    {
        $user = $this->user();

        if (! $user->hasRoleName(RoleName::Siswa)) {
            return null;
        }

        return Student::query()->where('user_id', $user->getKey())->with('classroom')->first();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
