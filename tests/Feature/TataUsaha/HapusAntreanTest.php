<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Registrations\CancelApprovedRegistration;
use App\Actions\Registrations\CancelRegistration;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Registrations\RegistrationCancelledBySchool;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;
use Tests\Support\TalentGate;

/*
 * D-155 (lead, 2026-10-16: "buat tu bisa hapus antrean surat"): Tata Usaha removes an approved registration from the
 * letter queue by cancelling it with a reason; unsent letters are revoked; members, guru pembimbing and the approver
 * who approved it are told. Fictional data only.
 */

uses(RefreshDatabase::class);

const D155_NOTE = 'Lomba diundur penyelenggara ke tahun depan.';

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $this->mentor = F::mentor();
    F::signature();
});

/** An approved team (approved by the Kepala Sekolah) in the TU queue. */
function d155Queued(object $t, array $names = ['Raka Pradipta', 'Bima Satria Wicaksono'], array $competition = []): Registration
{
    $registration = F::registration(RegistrationStatus::Disetujui, $names, $t->mentor, $competition);
    $registration->forceFill(['principal_decision_by' => $t->kepsek->getKey(), 'principal_decided_at' => now()])->save();

    return $registration->fresh();
}

function d155IssueStudentLetter(User $tu, Registration $registration): Letter
{
    return app(IssueLetters::class)->handle($tu, $registration, LetterType::Siswa, new LetterDraft(
        number: '400.3.8/313/MRD/X/2026',
        issuedOn: CarbonImmutable::today(),
        basisFrom: '',
        basisNumber: null,
        basisDate: null,
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    ));
}

/** @return list<User> */
function d155Members(Registration $registration): array
{
    return $registration->members()->with('student.user')->get()->map(fn ($m) => $m->student->user)->all();
}

// The Action ----------------------------------------------------------------------------------------------------

it('cancels an approved registration, revokes its unsent letters and tells members, guru and approver with the reason', function (): void {
    Notification::fake();
    $registration = d155Queued($this);
    $letter = d155IssueStudentLetter($this->tu, $registration);

    app(CancelApprovedRegistration::class)->handle($this->tu, $registration, "  Lomba diundur\n penyelenggara ke tahun depan.  ");

    $fresh = $registration->fresh();
    expect($fresh->status)->toBe(RegistrationStatus::Dibatalkan)
        ->and($fresh->cancelled_at)->not->toBeNull()
        ->and($letter->fresh()->revoked_at)->not->toBeNull()
        ->and($letter->fresh()->revoked_by)->toBe($this->tu->getKey())
        ->and($letter->fresh()->revoke_reason)->toBe(CancelApprovedRegistration::LETTER_REASON)
        // the queue no longer holds it, the number stays used
        ->and(Registration::query()->awaitingLetters()->count())->toBe(0)
        ->and(IssueLetters::isTaken(LetterType::Siswa, '400.3.8/313/MRD/X/2026'))->toBeTrue();

    $log = Activity::query()->where('event', 'registration.cancelled')->sole();
    expect($log->causer_id)->toBe($this->tu->getKey())
        ->and($log->properties['from'])->toBe('disetujui')
        ->and($log->properties['reason'])->toBe(CancelApprovedRegistration::REASON)
        ->and($log->properties['note'])->toBe(D155_NOTE)
        ->and($log->properties['letters_revoked'])->toBe([$letter->getKey()]);

    Notification::assertSentTo(d155Members($registration), RegistrationCancelledBySchool::class,
        fn (RegistrationCancelledBySchool $n): bool => $n->audience === 'siswa' && $n->note === D155_NOTE && $n->deciderRole === 'Tata Usaha');
    Notification::assertSentTo($this->mentor, RegistrationCancelledBySchool::class, fn (RegistrationCancelledBySchool $n): bool => $n->audience === 'guru');
    Notification::assertSentTo($this->kepsek, RegistrationCancelledBySchool::class, fn (RegistrationCancelledBySchool $n): bool => $n->audience === 'pemutus');
    Notification::assertNotSentTo($this->tu, RegistrationCancelledBySchool::class);

    // The registration reads its reason back from the audit row.
    expect($fresh->letterQueueCancellation())->toMatchArray(['note' => D155_NOTE])
        ->and($fresh->letterQueueCancellation()['by']?->is($this->tu))->toBeTrue();
});

it('does not tell the approver when Tata Usaha approved it itself', function (): void {
    Notification::fake();
    $registration = d155Queued($this);
    $registration->forceFill(['principal_decision_by' => $this->tu->getKey()])->save();

    app(CancelApprovedRegistration::class)->handle($this->tu, $registration->fresh(), D155_NOTE);

    Notification::assertNotSentTo([$this->tu, $this->kepsek], RegistrationCancelledBySchool::class);
    Notification::assertSentTo($this->mentor, RegistrationCancelledBySchool::class);
});

it('words the notices with who cancelled, the role and the reason', function (): void {
    $registration = d155Queued($this);
    $student = d155Members($registration)[0];

    $siswa = RegistrationCancelledBySchool::for($registration, 'siswa', $this->tu, D155_NOTE);
    $guru = RegistrationCancelledBySchool::for($registration, 'guru', $this->tu, D155_NOTE);
    $pemutus = RegistrationCancelledBySchool::for($registration, 'pemutus', $this->tu, D155_NOTE);

    expect($siswa->toArray($student)['message'])->toBe('Pendaftaranmu di National Science and Invention Fair (NSIF) 2026 dibatalkan oleh Sri Handayani (Tata Usaha) sebelum surat tugas dikirim. Alasan: '.D155_NOTE)
        ->and($siswa->toArray($student)['title'])->toBe('Pendaftaran dibatalkan')
        ->and($guru->toArray($this->mentor)['message'])->toStartWith('Pendaftaran binaan di National Science and Invention Fair (NSIF) 2026 (Raka Pradipta) dibatalkan oleh Sri Handayani (Tata Usaha)')
        ->and($pemutus->toArray($this->kepsek)['message'])->toContain('yang sudah disetujui dibatalkan oleh Sri Handayani (Tata Usaha)')
        ->and((string) $siswa->toMail($student)->render())->toContain('Alasan: '.D155_NOTE)
        ->and((string) $siswa->toMail($student)->render())->toContain('Kamu bisa mendaftar lagi')
        ->and((string) $guru->toMail($this->mentor)->render())->toContain('Alasan: '.D155_NOTE)->not->toContain('Kamu bisa');
});

it('lets only Tata Usaha remove a registration from the queue', function (RoleName $role): void {
    $registration = d155Queued($this);

    expect(fn () => app(CancelApprovedRegistration::class)->handle(F::staff($role), $registration, D155_NOTE))
        ->toThrow(AuthorizationException::class)
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui);
})->with([RoleName::KepalaSekolah, RoleName::Admin, RoleName::Kesiswaan, RoleName::Humas, RoleName::Guru]);

it('refuses a short reason and a registration that is no longer in the queue, changing nothing', function (): void {
    $registration = d155Queued($this);

    foreach (['', 'abc', str_repeat('a', 501)] as $note) {
        try {
            app(CancelApprovedRegistration::class)->handle($this->tu, $registration, $note);
            $this->fail('A bad reason was accepted.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('note');
        }
    }

    foreach ([RegistrationStatus::MenungguKepsek, RegistrationStatus::SuratTerbit, RegistrationStatus::Dibatalkan] as $status) {
        $other = d155Queued($this, ['Hana Safira']);
        $other->forceFill(['status' => $status])->save();

        try {
            app(CancelApprovedRegistration::class)->handle($this->tu, $other->fresh(), D155_NOTE);
            $this->fail('A registration outside the queue was cancelled.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('registration')
                ->and($other->fresh()->status)->toBe($status);
        }
    }

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and(Activity::query()->where('event', 'registration.cancelled')->count())->toBe(0);
});

it('still never lets the ketua cancel an approved registration', function (): void {
    $registration = d155Queued($this);
    $leader = $registration->leader->user;

    expect($leader->can('cancel', $registration))->toBeFalse()
        ->and(fn () => app(CancelRegistration::class)->handle($leader, $registration))->toThrow(AuthorizationException::class)
        ->and(CancelRegistration::LEADER_STATUSES)->not->toContain(RegistrationStatus::Disetujui);
});

// The TU screen ---------------------------------------------------------------------------------------------------

it('asks for the reason in the app dialog, names who is told, then cancels and opens the next registration', function (): void {
    Notification::fake();
    $first = d155Queued($this);
    $second = d155Queued($this, ['Hana Safira'], ['name' => 'OSN Tingkat Kota Semarang 2026']);

    $component = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $first])
        ->assertSee('Hapus dari antrean')
        ->assertSee('Untuk pendaftaran yang tidak jadi dibuatkan surat tugas.')
        ->call('askCancel')
        ->assertSet('cancellingId', $first->getKey())
        ->assertDispatched('sth-modal-open', id: SuratTugas::CANCEL_DIALOG)
        ->assertSee('Hapus dari antrean surat?')
        ->assertSee('2 siswa, guru pembimbing, dan Drs. Bambang Hartono, M.Pd. (Kepala Sekolah) yang menyetujuinya diberi tahu beserta alasannya.')
        ->assertSee('Pembatalan tidak bisa dikembalikan')
        // no reason: the message stands under the field and nothing happens
        ->call('cancelQueued')
        ->assertHasErrors(['cancelNote'])
        ->assertSee(CancelApprovedRegistration::MSG_NOTE);

    expect($first->fresh()->status)->toBe(RegistrationStatus::Disetujui);

    $component->set('cancelNote', D155_NOTE)
        ->call('cancelQueued')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: SuratTugas::CANCEL_DIALOG)
        ->assertDispatched('sth-toast', tone: 'success', message: 'Pendaftaran dibatalkan dan dihapus dari antrean. 2 siswa, guru pembimbing, dan Drs. Bambang Hartono, M.Pd. (Kepala Sekolah) yang menyetujuinya diberi tahu beserta alasannya.')
        ->assertDispatched('sth-badges', badges: ['tu.surat' => 1])
        ->assertDispatched('sth-address', url: route('tu.surat.show', $second))
        ->assertSet('registrationId', $second->getKey())
        ->assertSet('cancellingId', null)
        ->assertSet('cancelNote', '');

    expect($first->fresh()->status)->toBe(RegistrationStatus::Dibatalkan)
        ->and($second->fresh()->status)->toBe(RegistrationStatus::Disetujui);
});

it('does nothing on the confirm without the question, and answers a registration sent meanwhile with a toast', function (): void {
    $registration = d155Queued($this);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->set('cancelNote', D155_NOTE)
        ->call('cancelQueued')
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui);

    // Another tab sent the letters after the dialog opened.
    $component = Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('askCancel')
        ->set('cancelNote', D155_NOTE);
    $registration->forceFill(['status' => RegistrationStatus::SuratTerbit])->save();

    $component->call('cancelQueued')
        ->assertDispatched('sth-toast', tone: 'warning', message: SuratTugas::MSG_CANCELLED_ELSEWHERE)
        ->assertDispatched('sth-modal-close', id: SuratTugas::CANCEL_DIALOG)
        ->assertSet('cancellingId', null);

    expect($registration->fresh()->status)->toBe(RegistrationStatus::SuratTerbit);
});

it('forgets the question when the dialog closes', function (): void {
    $registration = d155Queued($this);

    Livewire::actingAs($this->tu)->test(SuratTugas::class, ['registration' => $registration])
        ->call('askCancel')
        ->set('cancelNote', 'setengah')
        ->call('forgetCancel')
        ->assertSet('cancellingId', null)
        ->assertSet('cancelNote', '')
        ->set('cancelNote', D155_NOTE)
        ->call('cancelQueued');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui);
});

// The student's side ------------------------------------------------------------------------------------------------

it('tells the student on Lomba saya who cancelled it and why', function (): void {
    Notification::fake();
    $registration = d155Queued($this, ['Raka Pradipta']);
    app(CancelApprovedRegistration::class)->handle($this->tu, $registration, D155_NOTE);

    $student = $registration->leader;
    $student->user->forceFill(['status' => UserStatus::Active, 'email' => 'raka.'.uniqid().'@example.test', 'email_verified_at' => now(), 'must_change_password' => false, 'password_changed_at' => now()])->save();
    TalentGate::pass($student->fresh());

    $this->actingAs($student->user->fresh())
        ->get(route('siswa.lomba-saya.show', $registration))
        ->assertOk()
        ->assertSee('Pendaftaran dibatalkan')
        ->assertSee('Dibatalkan oleh Sri Handayani (Tata Usaha) sebelum surat tugas dikirim. Alasan: '.D155_NOTE);
});
