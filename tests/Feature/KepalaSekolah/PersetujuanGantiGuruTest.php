<?php

declare(strict_types=1);

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\MentorChoice;
use App\Actions\Registrations\RejectRegistration;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Kepsek\Persetujuan;
use App\Livewire\Siswa\LombaSaya\Index as LombaSaya;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Approval\RegistrationRejected;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
| D-132 (lead, 2026-10-11): with the approval the Kepala Sekolah may put another guru pembimbing in the place of the
| one the student chose. One decision: the students read the final guru in their approval notice (also when nothing
| changed) and the new guru gets the approval as an appointment.
| D-134 (lead, same day): the guru who was replaced is told nothing. That account never heard that a student had
| chosen it (a new registration is told to the Kepala Sekolah and the team only), so "diganti" would be the first and
| only thing it hears about this registration.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $this->retno = gantiGuruMentor('Retno Wulandari, S.Pd.', 'Bu');
    $this->hendra = gantiGuruMentor('Hendra Kusuma, S.Pd., M.Si.', 'Pak');
});

function gantiGuruMentor(string $name, string $honorific): User
{
    $guru = F::mentor($name);
    $guru->staffProfile()->update(['honorific' => $honorific]);

    return $guru->fresh();
}

/** Team of two active students (so both get the notice), waiting for the Kepala Sekolah, guided by $mentor. */
function gantiGuruRegistration(?User $mentor, array $names = ['Nadia Putri Anindya', 'Dimas Arya Nugroho']): Registration
{
    $registration = F::registration(names: $names, mentor: $mentor);

    foreach ($registration->members()->with('student.user')->get() as $member) {
        $member->student->user->forceFill([
            'email' => 'siswa.'.uniqid().'@example.test',
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
            'must_change_password' => false,
        ])->save();
    }

    return $registration->fresh();
}

/** @return array{subject: string, lines: list<string>, action: ?string, url: ?string} */
function gantiGuruMail(object $notification, object $notifiable): array
{
    $mail = $notification->toMail($notifiable);
    expect($mail)->toBeInstanceOf(PlainMailMessage::class);

    $plain = fn (mixed $value): string => html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5);

    return [
        'subject' => (string) $mail->subject,
        'lines' => array_values(array_map($plain, $mail->introLines)),
        'action' => $mail->actionText,
        'url' => $mail->actionUrl,
    ];
}

// Action ----------------------------------------------------------------------------------------------

it('approves with another guru: the students read the final guru, the new guru is appointed, the old one hears nothing', function (): void {
    Notification::fake();
    $tu = F::staff(RoleName::TataUsaha);
    $registration = gantiGuruRegistration($this->retno);

    app(ApproveRegistration::class)->handle($this->kepsek, $registration, null, $this->hendra->getKey());

    $fresh = $registration->fresh();
    expect($fresh->status)->toBe(RegistrationStatus::Disetujui)
        ->and((int) $fresh->mentor_id)->toBe((int) $this->hendra->getKey())
        // The model the caller holds follows the decision too.
        ->and((int) $registration->mentor_id)->toBe((int) $this->hendra->getKey())
        ->and($registration->mentor->is($this->hendra))->toBeTrue();

    // Students: every member, the final guru and who was replaced.
    $members = ApproveRegistration::memberUsers($fresh);
    expect($members)->toHaveCount(2);

    foreach ($members as $member) {
        $notice = Notification::sent($member, RegistrationApproved::class)->sole();
        $mail = gantiGuruMail($notice, $member);

        expect($notice->audience)->toBe('siswa')
            ->and($mail['subject'])->toBe('Pendaftaran lomba disetujui')
            ->and($mail['lines'])->toBe([
                'Kepala Sekolah menyetujui pendaftaranmu di National Science and Invention Fair (NSIF) 2026.',
                'Guru pembimbing: Pak Hendra Kusuma, S.Pd., M.Si. (ditetapkan Kepala Sekolah, menggantikan Bu Retno Wulandari, S.Pd.).',
                'Tata Usaha sedang menyiapkan surat tugas. Kamu akan diberi tahu saat surat siap.',
            ])
            ->and($notice->toArray($member)['message'])->toBe('Kepala Sekolah menyetujui pendaftaranmu di National Science and Invention Fair (NSIF) 2026. '
                .'Guru pembimbing: Pak Hendra Kusuma, S.Pd., M.Si. (ditetapkan Kepala Sekolah, menggantikan Bu Retno Wulandari, S.Pd.).');
    }

    // The new guru: the approval notice, worded as an appointment, opening the registration in the guru area.
    $appointed = Notification::sent($this->hendra, RegistrationApproved::class)->sole();
    $mail = gantiGuruMail($appointed, $this->hendra);

    expect($appointed->audience)->toBe('guru')
        ->and($mail['subject'])->toBe('Ditetapkan sebagai guru pembimbing')
        ->and($mail['lines'])->toBe([
            'Kepala Sekolah menyetujui pendaftaran National Science and Invention Fair (NSIF) 2026 (Nadia Putri Anindya) dan menetapkan guru pembimbingnya: '
            .'Pak Hendra Kusuma, S.Pd., M.Si., menggantikan Bu Retno Wulandari, S.Pd.',
        ])
        ->and($mail['url'])->toBe(route('guru.binaan.show', ['registration' => $registration->getKey()]))
        ->and($appointed->toArray($this->hendra)['title'])->toBe('Ditetapkan sebagai guru pembimbing');

    // The guru who was replaced hears nothing (D-134): no notice of being replaced, and never the approval notice of
    // a binaan that account does not have.
    Notification::assertNothingSentTo($this->retno);
    expect(class_exists('App\\Notifications\\Approval\\MentorReplaced'))->toBeFalse();

    // Tata Usaha: as before.
    Notification::assertSentTo($tu, RegistrationApproved::class, fn (RegistrationApproved $n) => $n->audience === 'tu' && $n->mentorLine() === null);

    // Audit: the change with user ids only, then the approval.
    $change = Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->where('subject_id', $registration->getKey())->sole();
    expect($change->properties->all())->toBe(['from' => (int) $this->retno->getKey(), 'to' => (int) $this->hendra->getKey()])
        ->and((int) $change->causer_id)->toBe((int) $this->kepsek->getKey())
        ->and(json_encode($change->properties))->not->toContain('Retno')->not->toContain('Hendra')
        ->and(Activity::query()->where('event', 'registration.approved')->where('subject_id', $registration->getKey())->exists())->toBeTrue()
        ->and(ApproveRegistration::mentorWasReplaced($fresh))->toBeTrue();
});

it('names the guru in the students\' approval notice also when the Kepala Sekolah changed nothing', function (int|string|null $sameGuru): void {
    Notification::fake();
    $registration = gantiGuruRegistration($this->retno);

    app(ApproveRegistration::class)->handle($this->kepsek, $registration, null, $sameGuru === 'current' ? $this->retno->getKey() : $sameGuru);

    $fresh = $registration->fresh();
    $member = ApproveRegistration::memberUsers($fresh)->first();
    $mail = gantiGuruMail(Notification::sent($member, RegistrationApproved::class)->sole(), $member);

    expect((int) $fresh->mentor_id)->toBe((int) $this->retno->getKey())
        ->and($mail['lines'])->toBe([
            'Kepala Sekolah menyetujui pendaftaranmu di National Science and Invention Fair (NSIF) 2026.',
            'Guru pembimbing: Bu Retno Wulandari, S.Pd.',
            'Tata Usaha sedang menyiapkan surat tugas. Kamu akan diberi tahu saat surat siap.',
        ])
        ->and(gantiGuruMail(Notification::sent($this->retno, RegistrationApproved::class)->sole(), $this->retno))->toMatchArray([
            'subject' => 'Pendaftaran binaan disetujui',
            'lines' => ['Pendaftaran binaan di National Science and Invention Fair (NSIF) 2026 (Nadia Putri Anindya) disetujui Kepala Sekolah.'],
        ])
        ->and(Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->exists())->toBeFalse()
        ->and(ApproveRegistration::mentorWasReplaced($fresh))->toBeFalse();

    Notification::assertNothingSentTo($this->hendra);
})->with([
    'no guru given' => [null],
    'an empty choice' => [''],
    'the guru the registration already has' => ['current'],
]);

it('ends a name without a degree with a full stop, and a name with one without a second full stop', function (): void {
    expect(MentorChoice::closing('Bu Retno Wulandari'))->toBe('Bu Retno Wulandari.')
        ->and(MentorChoice::closing('Bu Retno Wulandari, S.Pd.'))->toBe('Bu Retno Wulandari, S.Pd.')
        ->and(MentorChoice::name(null))->toBeNull()
        ->and(MentorChoice::name($this->retno))->toBe('Bu Retno Wulandari, S.Pd.');
});

it('refuses a choice that is not a guru who can log in, and decides nothing', function (Closure $choice): void {
    Notification::fake();
    $registration = gantiGuruRegistration($this->retno);

    expect(fn () => app(ApproveRegistration::class)->handle($this->kepsek, $registration, null, $choice($this)))
        ->toThrow(function (ValidationException $e): void {
            expect($e->errors())->toBe(['mentor' => [ApproveRegistration::MSG_MENTOR]]);
        });

    $fresh = $registration->fresh();
    expect($fresh->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and((int) $fresh->mentor_id)->toBe((int) $this->retno->getKey())
        ->and(Activity::query()->whereIn('event', [ApproveRegistration::EVENT_MENTOR_CHANGED, 'registration.approved'])->exists())->toBeFalse();

    Notification::assertNothingSent();
})->with([
    'a Tata Usaha account' => [fn (object $t): int => (int) F::staff(RoleName::TataUsaha)->getKey()],
    'the Kepala Sekolah' => [fn (object $t): int => (int) $t->kepsek->getKey()],
    'a student' => [fn (object $t): int => (int) F::activeStudent('Hana Safira')->user_id],
    'a guru whose account is switched off' => [function (object $t): int {
        $t->hendra->forceFill(['status' => UserStatus::Disabled])->save();

        return (int) $t->hendra->getKey();
    }],
    'an unknown id' => [fn (object $t): int => 999_999],
    'not a number' => [fn (object $t): string => 'bukan-id'],
]);

it('lets only the Kepala Sekolah and Tata Usaha change the guru, and only while the registration waits', function (): void {
    $waiting = gantiGuruRegistration($this->retno);
    $approved = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->retno);
    $rejected = F::registration(RegistrationStatus::Ditolak, ['Fajar Ramadhan'], $this->retno);
    $unconfirmed = F::registration(RegistrationStatus::MenungguKonfirmasi, ['Raka Pradipta'], $this->retno);

    expect($this->kepsek->can('changeMentor', $waiting))->toBeTrue()
        // D-150: Tata Usaha approves too, "Ganti guru" included (D-132).
        ->and(F::staff(RoleName::TataUsaha)->can('changeMentor', $waiting))->toBeTrue();

    foreach ([$approved, $rejected, $unconfirmed] as $registration) {
        expect($this->kepsek->can('changeMentor', $registration))->toBeFalse();
    }

    foreach ([RoleName::Admin, RoleName::Kesiswaan, RoleName::Humas] as $role) {
        $actor = F::staff($role);

        expect($actor->can('changeMentor', $waiting))->toBeFalse();
        expect(fn () => app(ApproveRegistration::class)->handle($actor, $waiting, null, $this->hendra->getKey()))->toThrow(AuthorizationException::class);
    }

    // Neither guru decides, and a student never does.
    foreach ([$this->retno, $this->hendra, User::query()->findOrFail($waiting->leader->user_id)] as $actor) {
        expect($actor->can('changeMentor', $waiting))->toBeFalse();
        expect(fn () => app(ApproveRegistration::class)->handle($actor, $waiting, null, $this->hendra->getKey()))->toThrow(AuthorizationException::class);
    }

    // After the decision the guru stays: a second call with another guru changes nothing.
    expect(fn () => app(ApproveRegistration::class)->handle($this->kepsek, $approved, null, $this->hendra->getKey()))->toThrow(ValidationException::class);

    expect((int) $waiting->fresh()->mentor_id)->toBe((int) $this->retno->getKey())
        ->and($waiting->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and((int) $approved->fresh()->mentor_id)->toBe((int) $this->retno->getKey());
});

it('tells the replaced guru nothing, whatever the state of that account (D-134)', function (Closure $state): void {
    Notification::fake();
    $registration = gantiGuruRegistration($this->retno);
    $state($this->retno);

    app(ApproveRegistration::class)->handle($this->kepsek, $registration, null, $this->hendra->getKey());

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->getKey());
    Notification::assertNothingSentTo($this->retno);
    Notification::assertSentTo($this->hendra, RegistrationApproved::class);

    // The students still read both names: the decision is the same whoever is told.
    $member = ApproveRegistration::memberUsers($registration->fresh())->first();
    expect(Notification::sent($member, RegistrationApproved::class)->sole()->mentorLine())
        ->toBe('Guru pembimbing: Pak Hendra Kusuma, S.Pd., M.Si. (ditetapkan Kepala Sekolah, menggantikan Bu Retno Wulandari, S.Pd.).');
})->with([
    'an active guru' => [fn (User $guru) => null],
    'the account was switched off' => [fn (User $guru) => $guru->forceFill(['status' => UserStatus::Disabled])->save()],
    'the account moved to another role' => [fn (User $guru) => $guru->syncRoles([RoleName::Humas->value])],
]);

it('never tells the Kepala Sekolah about their own decision, also when that account is a guru too', function (): void {
    Notification::fake();
    // A guru who becomes Kepala Sekolah keeps the guru role (ApplyPrincipalReplacement), so students can choose that
    // account as their guru pembimbing.
    $this->kepsek->assignRole(RoleName::Guru->value);
    $kepsek = $this->kepsek->fresh();

    // 1. The student chose that account and the Kepala Sekolah hands the registration to another guru: nobody is
    //    told about their own click.
    $chosen = gantiGuruRegistration($kepsek);
    $dialog = Livewire::actingAs($kepsek)->test(Persetujuan::class)->call('askMentor', $chosen->getKey())->viewData('mentorDialog');

    expect($dialog['notices'])->toBe([
        'Siswa diberi tahu bahwa pendaftarannya disetujui dan siapa guru pembimbingnya.',
        'Guru pengganti diberi tahu bahwa ia ditetapkan sebagai guru pembimbing.',
    ])
        // Offered: every other guru, so neither the guru the row has nor this account itself.
        ->and(array_keys($dialog['options']))->toEqualCanonicalizing([(int) $this->retno->getKey(), (int) $this->hendra->getKey()]);

    app(ApproveRegistration::class)->handle($kepsek, $chosen, null, $this->hendra->getKey());

    Notification::assertNothingSentTo($kepsek);
    Notification::assertSentTo($this->hendra, RegistrationApproved::class);

    // 2. An approval without a change, of a registration whose guru is that same account: no notice of a binaan the
    //    account has just approved itself.
    $own = gantiGuruRegistration($kepsek, ['Hana Safira']);
    app(ApproveRegistration::class)->handle($kepsek, $own);

    Notification::assertNotSentTo($kepsek, RegistrationApproved::class);

    // 3. A forged choice of the account itself (the dialog does not offer it): the students still read the name,
    //    the account gets nothing, and neither does the guru who was replaced.
    $taken = gantiGuruRegistration($this->retno, ['Fajar Ramadhan']);
    app(ApproveRegistration::class)->handle($kepsek, $taken, null, $kepsek->getKey());
    $member = ApproveRegistration::memberUsers($taken->fresh())->first();

    expect((int) $taken->fresh()->mentor_id)->toBe((int) $kepsek->getKey())
        ->and(Notification::sent($member, RegistrationApproved::class)->sole()->mentorLine())
        ->toBe('Guru pembimbing: Drs. Bambang Hartono, M.Pd. (ditetapkan Kepala Sekolah, menggantikan Bu Retno Wulandari, S.Pd.).');

    Notification::assertNothingSentTo($kepsek);
    Notification::assertNothingSentTo($this->retno);
});

it('appoints a guru for a registration that had none, without a "menggantikan"', function (): void {
    Notification::fake();
    $registration = gantiGuruRegistration(null);

    app(ApproveRegistration::class)->handle($this->kepsek, $registration, null, $this->hendra->getKey());

    $member = ApproveRegistration::memberUsers($registration->fresh())->first();
    $change = Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->sole();

    expect(Notification::sent($member, RegistrationApproved::class)->sole()->mentorLine())
        ->toBe('Guru pembimbing: Pak Hendra Kusuma, S.Pd., M.Si. (ditetapkan Kepala Sekolah).')
        ->and(gantiGuruMail(Notification::sent($this->hendra, RegistrationApproved::class)->sole(), $this->hendra)['lines'])->toBe([
            'Kepala Sekolah menyetujui pendaftaran National Science and Invention Fair (NSIF) 2026 (Nadia Putri Anindya) dan menetapkan guru pembimbingnya: '
            .'Pak Hendra Kusuma, S.Pd., M.Si.',
        ])
        ->and($change->properties->all())->toBe(['from' => null, 'to' => (int) $this->hendra->getKey()]);

    Notification::assertNothingSentTo($this->retno);
});

it('leaves the guru line out when the registration has no guru, and reads a notice queued before D-132', function (): void {
    $registration = gantiGuruRegistration(null);
    $member = ApproveRegistration::memberUsers($registration)->first();

    // No guru: nothing to name.
    $none = RegistrationApproved::for($registration, 'siswa');
    // A payload from before the change has none of the new properties: the constructor alone is what it carries.
    $old = unserialize(serialize(new RegistrationApproved($registration, 'siswa')));

    foreach ([$none, $old] as $notice) {
        expect($notice->mentorLine())->toBeNull()
            ->and(gantiGuruMail($notice, $member)['lines'])->toBe([
                'Kepala Sekolah menyetujui pendaftaranmu di National Science and Invention Fair (NSIF) 2026.',
                'Tata Usaha sedang menyiapkan surat tugas. Kamu akan diberi tahu saat surat siap.',
            ]);
    }
});

it('sends a rejection to the guru the student chose: a rejection never changes the guru', function (): void {
    Notification::fake();
    $registration = gantiGuruRegistration($this->retno);

    app(RejectRegistration::class)->handle($this->kepsek, $registration, 'Jadwal bentrok dengan penilaian tengah semester.');

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey());
    Notification::assertSentTo($this->retno, RegistrationRejected::class);
    Notification::assertNothingSentTo($this->hendra);
});

it('moves the registration from the old guru\'s binaan to the new guru\'s', function (): void {
    Notification::fake();
    $registration = gantiGuruRegistration($this->retno);

    expect($this->retno->can('viewAsMentor', $registration))->toBeTrue()
        ->and($this->hendra->can('viewAsMentor', $registration))->toBeFalse();

    app(ApproveRegistration::class)->handle($this->kepsek, $registration, null, $this->hendra->getKey());
    $fresh = $registration->fresh();

    expect($this->retno->can('viewAsMentor', $fresh))->toBeFalse()
        ->and($this->hendra->can('viewAsMentor', $fresh))->toBeTrue();

    $this->actingAs($this->retno)->get(route('guru.binaan.show', ['registration' => $fresh->getKey()]))->assertNotFound();
    $this->actingAs($this->hendra)->get(route('guru.binaan.show', ['registration' => $fresh->getKey()]))->assertOk();
});

// Screen ----------------------------------------------------------------------------------------------

it('offers "Ganti guru" on waiting rows and opens a dialog with every other guru', function (): void {
    $registration = gantiGuruRegistration($this->retno);
    $third = gantiGuruMentor('Maya Anggraini, S.Pd.', 'Bu');
    $off = gantiGuruMentor('Agus Setiawan, S.Or.', 'Pak');
    $off->forceFill(['status' => UserStatus::Disabled])->save();
    F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->retno, ['name' => 'Lomba yang sudah disetujui']);

    $component = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->assertSeeHtml("wire:click=\"askMentor('".$registration->getKey()."')\"")
        ->assertSeeHtml('aria-label="Ganti guru pembimbing Nadia Putri Anindya dkk., National Science and Invention Fair (NSIF) 2026"')
        ->assertSee('Tidak ada pendaftaran yang dipilih.');

    expect($component->viewData('mentorDialog'))->toBeNull();

    $component->call('askMentor', $registration->getKey())
        ->assertDispatched('sth-modal-open', id: Persetujuan::MENTOR_DIALOG)
        ->assertSet('mentorFor', $registration->getKey())
        ->assertSet('mentorChoice', '')
        ->assertSee('Setujui dengan guru pembimbing lain?')
        ->assertSee('Pilihan siswa:')
        ->assertSee('Guru pembimbing pengganti')
        ->assertSee('Setujui dengan guru ini');

    $dialog = $component->viewData('mentorDialog');

    expect($dialog['current'])->toBe('Bu Retno Wulandari, S.Pd.')
        ->and($dialog['who'])->toBe('Tim 2 siswa: Nadia Putri Anindya, Dimas Arya Nugroho')
        // Every guru who can log in, by name, without the one the student chose and without a switched-off account.
        ->and($dialog['options'])->toBe([
            (int) $this->hendra->getKey() => 'Pak Hendra Kusuma, S.Pd., M.Si.',
            (int) $third->getKey() => 'Bu Maya Anggraini, S.Pd.',
        ])
        // Who is told: the students and the new guru. Nothing about the guru the student chose (D-134).
        ->and($dialog['notices'])->toBe([
            'Siswa diberi tahu bahwa pendaftarannya disetujui dan siapa guru pembimbingnya.',
            'Guru pengganti diberi tahu bahwa ia ditetapkan sebagai guru pembimbing.',
        ]);

    $component->assertDontSee('Notifikasi persetujuan untuk siswa menyebut')
        ->assertDontSee('diberi tahu bahwa guru pembimbing diganti');

    // Nothing was decided by opening the dialog.
    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('promises a notice in the dialog only to the people who get one (D-109)', function (): void {
    $registration = F::registration(names: ['Nadia Putri Anindya'], mentor: $this->retno);
    // The student of this fixture has not activated the account.
    $registration->leader->user->forceFill(['status' => UserStatus::Disabled])->save();

    $dialog = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('askMentor', $registration->getKey())
        ->viewData('mentorDialog');

    expect($dialog['notices'])->toBe([
        'Akun siswa tidak aktif, jadi siswa tidak menerima notifikasi.',
        'Guru pengganti diberi tahu bahwa ia ditetapkan sebagai guru pembimbing.',
    ])->and($dialog['who'])->toBe('Nadia Putri Anindya');

    // A team of which one member can log in: the sentence says so.
    $team = gantiGuruRegistration($this->retno, ['Hana Safira', 'Fajar Ramadhan']);
    $team->members()->with('student.user')->get()->last()->student->user->forceFill(['status' => UserStatus::Disabled])->save();

    $dialog = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('askMentor', $team->getKey())
        ->viewData('mentorDialog');

    expect($dialog['notices'])->toBe([
        'Siswa yang akunnya aktif diberi tahu bahwa pendaftarannya disetujui dan siapa guru pembimbingnya.',
        'Guru pengganti diberi tahu bahwa ia ditetapkan sebagai guru pembimbing.',
    ]);
});

it('approves the row with the guru picked in the dialog and shows who was replaced', function (): void {
    Notification::fake();
    $registration = gantiGuruRegistration($this->retno);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('askMentor', $registration->getKey())
        ->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('approveWithMentor')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: Persetujuan::MENTOR_DIALOG)
        ->assertDispatched('sth-toast', message: 'Pendaftaran disetujui dengan guru pembimbing Pak Hendra Kusuma, S.Pd., M.Si. Masuk antrean TU.', tone: 'success')
        ->assertSet('mentorFor', null)
        ->assertSet('mentorChoice', '')
        ->assertSet('recent', [$registration->getKey() => 'ok'])
        ->assertSet('replaced', [$registration->getKey() => 'Bu Retno Wulandari, S.Pd.'])
        ->assertSee('Disetujui · masuk antrean TU')
        ->assertSee('Pak Hendra Kusuma, S.Pd., M.Si.')
        ->assertSee('Ditetapkan Kepala Sekolah, menggantikan Bu Retno Wulandari, S.Pd.')
        // The decided row no longer offers the change.
        ->assertDontSeeHtml("wire:click=\"askMentor('".$registration->getKey()."')\"");

    $fresh = $registration->fresh();
    expect($fresh->status)->toBe(RegistrationStatus::Disetujui)
        ->and((int) $fresh->mentor_id)->toBe((int) $this->hendra->getKey());

    Notification::assertSentTo($this->hendra, RegistrationApproved::class);
    Notification::assertNothingSentTo($this->retno);
});

it('asks for a guru before it approves, and does nothing without the dialog', function (): void {
    Notification::fake();
    $registration = gantiGuruRegistration($this->retno);

    // No dialog was opened: a stray call is a silent no-op (D-128), never an error page.
    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('approveWithMentor')
        ->assertOk()
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);

    // Dialog open, nothing picked: the field says so and the row still waits.
    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('askMentor', $registration->getKey())
        ->call('approveWithMentor')
        ->assertHasErrors(['mentorChoice'])
        ->assertSee('Pilih guru pembimbing pengganti.')
        ->assertSet('mentorFor', $registration->getKey())
        ->assertNotDispatched('sth-modal-close');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey());

    Notification::assertNothingSent();
});

it('puts a refused guru under the field and keeps the dialog open', function (): void {
    Notification::fake();
    $registration = gantiGuruRegistration($this->retno);
    $tu = F::staff(RoleName::TataUsaha);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('askMentor', $registration->getKey())
        // A forged value: an account that is not a guru.
        ->set('mentorChoice', (string) $tu->getKey())
        ->call('approveWithMentor')
        ->assertHasErrors(['mentorChoice'])
        ->assertSee(ApproveRegistration::MSG_MENTOR)
        ->assertSet('mentorFor', $registration->getKey())
        ->assertSet('mentorChoice', '')
        ->assertNotDispatched('sth-modal-close');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey());

    Notification::assertNothingSent();
});

it('treats the guru the row already has as a plain approval', function (): void {
    Notification::fake();
    $registration = gantiGuruRegistration($this->retno);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('askMentor', $registration->getKey())
        // Not in the pick list, so only a forged value: nothing is "replaced".
        ->set('mentorChoice', (string) $this->retno->getKey())
        ->call('approveWithMentor')
        ->assertDispatched('sth-toast', message: 'Pendaftaran disetujui. Masuk antrean TU.', tone: 'success')
        ->assertSet('replaced', [])
        ->assertDontSee('Ditetapkan Kepala Sekolah');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey());

    // The guru stayed: the plain approval notice of a binaan, nothing else.
    Notification::assertSentTo($this->retno, RegistrationApproved::class, fn (RegistrationApproved $n) => $n->audience === 'guru' && ! $n->mentorChanged);
});

it('answers a row the student cancelled meanwhile with a toast, in the dialog and at its confirm step', function (): void {
    Notification::fake();
    $registration = gantiGuruRegistration($this->retno);

    $component = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('askMentor', $registration->getKey())
        ->set('mentorChoice', (string) $this->hendra->getKey());

    $registration->forceFill(['status' => RegistrationStatus::Dibatalkan])->save();

    $component->call('approveWithMentor')
        ->assertOk()
        ->assertDispatched('sth-toast', message: 'Pendaftaran ini sudah dibatalkan siswa. Daftar sudah diperbarui.', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: Persetujuan::MENTOR_DIALOG)
        ->assertSet('mentorFor', null);

    // And "Ganti guru" on a row that is gone answers the same way, not with a 404.
    $component->call('askMentor', $registration->getKey())
        ->assertOk()
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('mentorFor', null);

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey());
    Notification::assertNothingSent();
});

it('answers 404 for "Ganti guru" on a row that never was in the queue, and refuses every other role', function (): void {
    $approved = F::registration(RegistrationStatus::Disetujui, ['Hana Safira'], $this->retno);
    $waiting = gantiGuruRegistration($this->retno);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('askMentor', $approved->getKey())
        ->assertNotFound();

    foreach ([RoleName::Admin, RoleName::Kesiswaan, RoleName::Guru] as $role) {
        Livewire::actingAs(F::staff($role))->test(Persetujuan::class)->assertForbidden();
    }

    expect($waiting->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek);
});

it('keeps the student\'s guru for "Setujui" and "Setujui terpilih"', function (): void {
    Notification::fake();
    $one = gantiGuruRegistration($this->retno, ['Nadia Putri Anindya']);
    $two = gantiGuruRegistration($this->hendra, ['Hana Safira']);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('approve', $one->getKey())
        ->set('selected', [$two->getKey()])
        ->call('approveSelected')
        ->assertSet('replaced', []);

    expect((int) $one->fresh()->mentor_id)->toBe((int) $this->retno->getKey())
        ->and((int) $two->fresh()->mentor_id)->toBe((int) $this->hendra->getKey())
        ->and(Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->exists())->toBeFalse();

    // Each guru hears about the own binaan only, as a plain approval.
    foreach ([$this->retno, $this->hendra] as $guru) {
        expect(Notification::sent($guru, RegistrationApproved::class))->toHaveCount(1)
            ->and(Notification::sent($guru, RegistrationApproved::class)->sole()->mentorChanged)->toBeFalse();
    }
});

// Student screen ----------------------------------------------------------------------------------------

it('says "Ditetapkan Kepala Sekolah" on Lomba saya only when the guru was replaced', function (): void {
    Notification::fake();
    $changed = gantiGuruRegistration($this->retno, ['Nadia Putri Anindya']);
    $kept = gantiGuruRegistration($this->retno, ['Hana Safira']);

    app(ApproveRegistration::class)->handle($this->kepsek, $changed, null, $this->hendra->getKey());
    app(ApproveRegistration::class)->handle($this->kepsek, $kept);

    Livewire::actingAs(User::query()->findOrFail($changed->leader->user_id))
        ->test(LombaSaya::class, ['registration' => $changed->fresh()])
        ->assertSee('Pak Hendra Kusuma, S.Pd., M.Si.')
        ->assertSee('Ditetapkan Kepala Sekolah')
        ->assertDontSee('Retno Wulandari');

    Livewire::actingAs(User::query()->findOrFail($kept->leader->user_id))
        ->test(LombaSaya::class, ['registration' => $kept->fresh()])
        ->assertSee('Bu Retno Wulandari, S.Pd.')
        ->assertDontSee('Ditetapkan Kepala Sekolah');
});
