<?php

declare(strict_types=1);

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\MentorChoice;
use App\Actions\Registrations\RejectRegistration;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Kepsek\Persetujuan;
use App\Livewire\Siswa\Lomba\Detail;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Approval\RegistrationRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
| D-150 (FS1 Paket B): menunggu_kepsek reads "Menunggu persetujuan" (the value stays), the notices and screens name
| the role that decided (StaffRoleLabel::forDecision()), and before a decision the texts speak of "sekolah".
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani, S.E.']);
    $this->retno = F::mentor('Retno Wulandari, S.Pd.');
    $this->retno->staffProfile()->update(['honorific' => 'Bu']);
    $this->hendra = F::mentor('Hendra Kusuma, S.Pd., M.Si.');
});

/** A waiting registration whose members can log in (so they get the notices and open Lomba saya). */
function deciderRegistration(?User $mentor, array $names = ['Nadia Putri Anindya']): Registration
{
    $registration = F::registration(names: $names, mentor: $mentor);

    foreach ($registration->members()->with('student.user')->get() as $member) {
        $member->student->user->forceFill([
            'email' => 'siswa.'.uniqid().'@example.test',
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();
    }

    return $registration->fresh();
}

/** @return list<string> */
function deciderLines(object $notification, object $notifiable): array
{
    return array_values(array_map(
        fn (mixed $line): string => html_entity_decode((string) $line, ENT_QUOTES | ENT_HTML5),
        $notification->toMail($notifiable)->introLines,
    ));
}

it('labels menunggu_kepsek "Menunggu persetujuan" without changing the value', function (): void {
    expect(RegistrationStatus::MenungguKepsek->label())->toBe('Menunggu persetujuan')
        ->and(RegistrationStatus::MenungguKepsek->value)->toBe('menunggu_kepsek');
});

it('names the deciding Tata Usaha in the student notice and the guru line', function (): void {
    Notification::fake();
    $colleague = F::staff(RoleName::TataUsaha, ['name' => 'Dewi Lestari, S.Kom.']);
    $registration = deciderRegistration($this->retno);
    $student = User::query()->findOrFail($registration->leader->user_id);
    $retno = MentorChoice::name($this->retno->fresh('staffProfile'));
    $hendra = MentorChoice::name($this->hendra->fresh('staffProfile'));

    app(ApproveRegistration::class)->handle($this->tu, $registration, null, $this->hendra->getKey());

    $toStudent = null;
    Notification::assertSentTo($student, RegistrationApproved::class, function (RegistrationApproved $n) use (&$toStudent): bool {
        $toStudent = $n;

        return true;
    });
    expect(deciderLines($toStudent, $student))->toContain(
        'Pendaftaranmu di National Science and Invention Fair (NSIF) 2026 disetujui oleh Sri Handayani, S.E. (Tata Usaha).',
        'Guru pembimbing: '.$hendra.' (ditetapkan Tata Usaha, menggantikan '.$retno.').',
    );

    $toGuru = null;
    Notification::assertSentTo($this->hendra, RegistrationApproved::class, function (RegistrationApproved $n) use (&$toGuru): bool {
        $toGuru = $n;

        return true;
    });
    expect(deciderLines($toGuru, $this->hendra)[0])
        ->toStartWith('Tata Usaha menyetujui pendaftaran National Science and Invention Fair (NSIF) 2026 (Nadia Putri Anindya) dan menetapkan guru pembimbingnya: ');

    $toTu = null;
    Notification::assertSentTo($colleague, RegistrationApproved::class, function (RegistrationApproved $n) use (&$toTu): bool {
        $toTu = $n;

        return $n->audience === 'tu';
    });
    expect(deciderLines($toTu, $colleague)[0])
        ->toBe('Sri Handayani, S.E. (Tata Usaha) menyetujui pendaftaran National Science and Invention Fair (NSIF) 2026 (Nadia Putri Anindya). Isi nomor surat lalu buat PDF-nya.');
});

it('names the deciding role in the guru notice and the rejection', function (): void {
    Notification::fake();
    $kept = deciderRegistration($this->retno);
    app(ApproveRegistration::class)->handle($this->kepsek, $kept);

    $toGuru = null;
    Notification::assertSentTo($this->retno, RegistrationApproved::class, function (RegistrationApproved $n) use (&$toGuru): bool {
        $toGuru = $n;

        return true;
    });
    expect(deciderLines($toGuru, $this->retno)[0])
        ->toBe('Pendaftaran binaan di National Science and Invention Fair (NSIF) 2026 (Nadia Putri Anindya) disetujui Kepala Sekolah.');

    $rejected = deciderRegistration($this->hendra, ['Dimas Arya Nugroho']);
    app(RejectRegistration::class)->handle($this->tu, $rejected, 'Jadwal bentrok dengan penilaian tengah semester.');
    $student = User::query()->findOrFail($rejected->leader->user_id);

    $toStudent = null;
    Notification::assertSentTo($student, RegistrationRejected::class, function (RegistrationRejected $n) use (&$toStudent): bool {
        $toStudent = $n;

        return true;
    });
    expect(deciderLines($toStudent, $student)[0])->toBe('Pendaftaranmu di National Science and Invention Fair (NSIF) 2026 tidak disetujui Tata Usaha.');

    $toMentor = null;
    Notification::assertSentTo($this->hendra, RegistrationRejected::class, function (RegistrationRejected $n) use (&$toMentor): bool {
        $toMentor = $n;

        return true;
    });
    expect(deciderLines($toMentor, $this->hendra)[0])
        ->toBe('Pendaftaran binaan di National Science and Invention Fair (NSIF) 2026 (Dimas Arya Nugroho) tidak disetujui Tata Usaha.');
});

it('keeps a notice queued before this change readable and speaks of "sekolah" without a decider', function (): void {
    $registration = deciderRegistration($this->retno);
    $student = User::query()->findOrFail($registration->leader->user_id);

    $old = unserialize(serialize(new RegistrationApproved($registration, 'siswa')));
    expect(deciderLines($old, $student)[0])->toBe('Pendaftaranmu di National Science and Invention Fair (NSIF) 2026 disetujui sekolah.');

    $rejected = unserialize(serialize(new RegistrationRejected($registration, 'siswa')));
    expect(deciderLines($rejected, $student)[0])->toBe('Pendaftaranmu di National Science and Invention Fair (NSIF) 2026 tidak disetujui sekolah.');
});

it('says "Ditetapkan Tata Usaha" on Lomba saya and in Persetujuan after a TU replacement', function (): void {
    Notification::fake();
    $registration = deciderRegistration($this->retno);
    $retno = MentorChoice::name($this->retno->fresh('staffProfile'));

    Livewire::actingAs($this->tu)->test(Persetujuan::class)
        ->call('askMentor', $registration->getKey())
        ->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('approveWithMentor')
        ->assertSee('Ditetapkan Tata Usaha, menggantikan '.$retno)
        ->assertDontSee('Ditetapkan Kepala Sekolah');

    Livewire::actingAs(User::query()->findOrFail($registration->leader->user_id))
        ->test(LombaSaya::class, ['registration' => $registration->fresh()])
        ->assertSee('Ditetapkan Tata Usaha')
        ->assertSee('Disetujui oleh Sri Handayani, S.E. (Tata Usaha).')
        ->assertDontSee('Ditetapkan Kepala Sekolah');
});

it('names the deciding role on a rejection and on the TU letter queue', function (): void {
    $rejected = deciderRegistration($this->retno);
    app(RejectRegistration::class)->handle($this->tu, $rejected, 'Jadwal bentrok dengan penilaian tengah semester.');

    Livewire::actingAs(User::query()->findOrFail($rejected->leader->user_id))
        ->test(LombaSaya::class, ['registration' => $rejected->fresh()])
        ->assertSee('Catatan Tata Usaha:')
        ->assertDontSee('Catatan Kepala Sekolah');

    $approved = deciderRegistration($this->hendra, ['Dimas Arya Nugroho']);
    app(ApproveRegistration::class)->handle($this->kepsek, $approved);

    $this->actingAs($this->tu)->get(route('tu.beranda'))->assertOk()->assertSee('Disetujui Kepala Sekolah ·');
});

it('speaks of "sekolah" where no decider is known yet', function (): void {
    $registration = deciderRegistration($this->retno);
    $student = User::query()->findOrFail($registration->leader->user_id);

    Livewire::actingAs($student)->test(PendaftaranForm::class, ['competitionId' => $registration->competition_id])
        ->assertSee('Sekolah menyetujui')
        ->assertDontSee('Kepala Sekolah menyetujui');

    Livewire::actingAs($student)->test(Detail::class, ['competition' => $registration->competition])
        ->assertSee('Pendaftaran perlu persetujuan sekolah sebelum surat tugas dibuat.');

    Livewire::actingAs($student)->test(LombaSaya::class, ['registration' => $registration])
        ->assertSee('Menunggu persetujuan sekolah.')
        ->assertDontSee('Menunggu keputusan Kepala Sekolah');
});
