<?php

declare(strict_types=1);

use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\RevokeLetter;
use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\ApproveRevision;
use App\Actions\Registrations\ChangeMentor;
use App\Actions\Registrations\ChangeRegistration;
use App\Enums\LetterType;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\AuditLog;
use App\Livewire\Kepsek\Persetujuan;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Livewire\Kesiswaan\Lomba\Pendaftar;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Registrations\MentorAssigned;
use App\Notifications\Support\PlainMailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
| SV8, D-198 (the lead, item 4 "Keduanya"): the student chooses the guru pembimbing, and Kesiswaan (or Admin,
| `registration.mentor`) may fill it in or replace it on Kelola lomba until the laporan hasil is sent. Fixed 2026-11-09:
| an issued letter no longer blocks it (Tata Usaha has no separate "cabut" step); the letters stay as printed (D-108)
| and Tata Usaha is told to make a Koreksi, as after an approved revision (D-199), but only for a GURU letter: only that
| letter prints the guru pembimbing, the student letter does not.
| Who is told follows the approval's mentor change (D-132, D-134): the members read who their guru is now, the new guru
| is told as an appointment (not while the registration waits for its lomba, D-148), Tata Usaha only when a guru letter
| exists, the guru who was replaced hears nothing, nobody hears about their own action. Audit
| `registration.mentor_changed` with user ids only, source kesiswaan and `guru_letter_issued`. Fictional people only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, ['name' => 'Dwi Rahayu, S.Pd.']);
    $this->admin = F::staff(RoleName::Admin, ['name' => 'Operator STH']);
    $this->retno = ggpMentor('Retno Wulandari, S.Pd.', 'Bu');
    $this->hendra = ggpMentor('Hendra Kusuma, S.Pd., M.Si.', 'Pak');
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani']);
});

function ggpMentor(string $name, string $honorific): User
{
    $guru = F::mentor($name);
    $guru->staffProfile()->update(['honorific' => $honorific]);

    return $guru->fresh();
}

/** A team of two active students (both get the notices) in $status, guided by $mentor. */
function ggpRegistration(RegistrationStatus $status = RegistrationStatus::MenungguKepsek, ?User $mentor = null, array $names = ['Nadia Putri Anindya', 'Dimas Arya Nugroho']): Registration
{
    $registration = F::registration($status, $names, $mentor);

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
function ggpMail(object $notification, object $notifiable): array
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

/** An issued letter of $registration: the student letter by default (it prints no guru), or the guru letter. */
function ggpLetter(Registration $registration, bool $revoked = false, LetterType $type = LetterType::Siswa): Letter
{
    return Letter::factory()->create(array_filter([
        'registration_id' => $registration->getKey(),
        'type' => $type,
        'revoked_at' => $revoked ? now() : null,
    ]));
}

function ggpChanges(): int
{
    return Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->count();
}

/** A filled-in guru letter form (fictional numbers). */
function ggpDraft(string $number = '400.3.8.6/1681/2026'): LetterDraft
{
    return new LetterDraft(
        number: $number,
        issuedOn: CarbonImmutable::parse('2026-10-01'),
        basisFrom: 'Panitia NSIF 2026',
        basisNumber: '017/PAN-NSIF/IX/2026',
        basisDate: CarbonImmutable::parse('2026-09-15'),
        scheduleDay: 'Sabtu',
        scheduleDate: '7 November 2026',
        scheduleTime: '07.00 WIB s.d Selesai',
        place: 'Daring',
    );
}

// Action: who is told ----------------------------------------------------------------------------------------------

it('replaces the guru before the letters: the students read their guru, the new guru is appointed, the old one hears nothing', function (RegistrationStatus $status): void {
    Notification::fake();
    $registration = ggpRegistration($status, $this->retno);

    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->hendra->getKey());

    $fresh = $registration->fresh();
    expect((int) $fresh->mentor_id)->toBe((int) $this->hendra->getKey())
        ->and($fresh->status)->toBe($status)
        // The model the caller holds follows the change.
        ->and($registration->mentor->is($this->hendra))->toBeTrue();

    $members = ApproveRegistration::memberUsers($fresh);
    expect($members)->toHaveCount(2);

    foreach ($members as $member) {
        $notice = Notification::sent($member, MentorAssigned::class)->sole();
        $mail = ggpMail($notice, $member);

        expect($notice->audience)->toBe('siswa')
            ->and($mail['subject'])->toBe('Guru pembimbing diganti')
            ->and($mail['lines'])->toBe([
                'Guru pembimbing pendaftaranmu di National Science and Invention Fair (NSIF) 2026 diganti.',
                'Guru pembimbing: Pak Hendra Kusuma, S.Pd., M.Si. (ditetapkan Kesiswaan, menggantikan Bu Retno Wulandari, S.Pd.).',
            ])
            ->and($mail['action'])->toBe('Lihat pendaftaran')
            ->and($mail['url'])->toBe(route('siswa.lomba-saya.show', ['registration' => $registration->getKey()]))
            ->and($notice->toArray($member)['message'])->toBe('Guru pembimbing pendaftaranmu di National Science and Invention Fair (NSIF) 2026 diganti. '
                .'Guru pembimbing: Pak Hendra Kusuma, S.Pd., M.Si. (ditetapkan Kesiswaan, menggantikan Bu Retno Wulandari, S.Pd.).');
    }

    $appointed = Notification::sent($this->hendra, MentorAssigned::class)->sole();
    $mail = ggpMail($appointed, $this->hendra);

    expect($appointed->audience)->toBe('guru')
        ->and($mail['subject'])->toBe('Ditetapkan sebagai guru pembimbing')
        ->and($mail['lines'])->toBe([
            'Kesiswaan menetapkan guru pembimbing pendaftaran National Science and Invention Fair (NSIF) 2026 (Nadia Putri Anindya): '
            .'Pak Hendra Kusuma, S.Pd., M.Si., menggantikan Bu Retno Wulandari, S.Pd.',
        ])
        ->and($mail['url'])->toBe(route('guru.binaan.show', ['registration' => $registration->getKey()]));

    // D-134: the guru who was replaced hears nothing; and no approval notice goes anywhere. No letter: Tata Usaha neither.
    Notification::assertNothingSentTo($this->retno);
    Notification::assertNothingSentTo($this->kesiswaan);
    Notification::assertNothingSentTo($this->tu);
    Notification::assertNotSentTo($members, RegistrationApproved::class);
    expect(Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->sole()->properties['guru_letter_issued'])->toBeFalse();
})->with([
    'menunggu konfirmasi' => [RegistrationStatus::MenungguKonfirmasi],
    'menunggu persetujuan' => [RegistrationStatus::MenungguKepsek],
    'disetujui, surat belum dibuat' => [RegistrationStatus::Disetujui],
]);

it('fills in a missing guru and names the role of an Admin', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::Disetujui, null);

    app(ChangeMentor::class)->handle($this->admin, $registration, (string) $this->hendra->getKey());

    $member = ApproveRegistration::memberUsers($registration->fresh())->first();
    $mail = ggpMail(Notification::sent($member, MentorAssigned::class)->sole(), $member);

    expect($mail['subject'])->toBe('Guru pembimbing ditetapkan')
        ->and($mail['lines'])->toBe([
            'Guru pembimbing pendaftaranmu di National Science and Invention Fair (NSIF) 2026 sudah ditetapkan.',
            'Guru pembimbing: Pak Hendra Kusuma, S.Pd., M.Si. (ditetapkan Admin).',
        ])
        ->and(ggpMail(Notification::sent($this->hendra, MentorAssigned::class)->sole(), $this->hendra)['lines'])->toBe([
            'Admin menetapkan guru pembimbing pendaftaran National Science and Invention Fair (NSIF) 2026 (Nadia Putri Anindya): Pak Hendra Kusuma, S.Pd., M.Si.',
        ]);

    $audit = Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->sole();
    expect($audit->properties->toArray())->toBe(['from' => null, 'to' => (int) $this->hendra->getKey(), 'source' => ChangeMentor::SOURCE, 'guru_letter_issued' => false]);
});

it('tells only the ketua while the registration waits for its lomba, never a guru (D-148)', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguLomba, $this->retno, ['Nadia Putri Anindya']);

    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->hendra->getKey());

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->getKey())
        ->and(ChangeMentor::mentorToTell($registration->fresh()))->toBeNull();

    $ketua = $registration->leader->user;
    Notification::assertSentTo($ketua, MentorAssigned::class, fn (MentorAssigned $n): bool => $n->audience === 'siswa');
    Notification::assertNothingSentTo($this->hendra);
    Notification::assertNothingSentTo($this->retno);
});

it('tells nobody about their own action: a Kesiswaan account that is a guru too and picks itself', function (): void {
    Notification::fake();
    $this->kesiswaan->assignRole(RoleName::Guru->value);
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);

    app(ChangeMentor::class)->handle($this->kesiswaan->fresh(), $registration, $this->kesiswaan->getKey());

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->kesiswaan->getKey());
    Notification::assertNothingSentTo($this->kesiswaan);
    Notification::assertNothingSentTo($this->retno);
    Notification::assertSentTimes(MentorAssigned::class, 2);
});

it('writes user ids only into the audit row, with the source kesiswaan (D-134)', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);

    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->hendra->getKey());

    $audit = Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->sole();
    // Every row about the registration (its model events too); the fixtures' own user rows carry their names.
    $logged = Activity::query()->where('log_name', 'registration')->get()->map(fn (Activity $a): string => json_encode([$a->description, $a->properties, $a->attribute_changes]))->implode(' ');

    expect($audit->properties->toArray())->toBe(['from' => (int) $this->retno->getKey(), 'to' => (int) $this->hendra->getKey(), 'source' => 'kesiswaan', 'guru_letter_issued' => false])
        ->and($audit->causer?->is($this->kesiswaan))->toBeTrue()
        ->and($audit->subject_id)->toBe($registration->getKey())
        ->and($logged)->not->toContain('Hendra')
        ->and($logged)->not->toContain('Retno')
        ->and($logged)->not->toContain('Nadia');
});

// Action: when it is refused --------------------------------------------------------------------------------------

it('changes the guru after the guru letter, leaves it alone and tells Tata Usaha to make a Koreksi (fixed 2026-11-09)', function (RegistrationStatus $status): void {
    Notification::fake();
    $registration = ggpRegistration($status, $this->retno);
    $letter = ggpLetter($registration, type: LetterType::Guru);
    $printed = $letter->fresh()->printed;
    $otherTu = F::staff(RoleName::TataUsaha, ['name' => 'Wahyu Pratomo']);
    $offTu = F::staff(RoleName::TataUsaha, ['name' => 'Rina Kartika']);
    $offTu->forceFill(['status' => UserStatus::Disabled])->save();

    expect($this->kesiswaan->can('assignMentor', $registration))->toBeTrue();

    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->hendra->getKey());

    $fresh = $registration->fresh();
    expect((int) $fresh->mentor_id)->toBe((int) $this->hendra->getKey())
        ->and($fresh->status)->toBe($status)
        // D-108: the issued letter is not touched; Tata Usaha corrects it.
        ->and($letter->fresh()->revoked_at)->toBeNull()
        ->and($letter->fresh()->printed)->toBe($printed)
        ->and(ggpChanges())->toBe(1)
        ->and(Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->sole()->properties->toArray())
        ->toBe(['from' => (int) $this->retno->getKey(), 'to' => (int) $this->hendra->getKey(), 'source' => 'kesiswaan', 'guru_letter_issued' => true]);

    // The students and the new guru as before; the replaced guru nothing (D-134).
    Notification::assertSentTimes(MentorAssigned::class, 2 + 1 + 2);
    Notification::assertSentTo($this->hendra, MentorAssigned::class, fn (MentorAssigned $n): bool => $n->audience === 'guru');
    Notification::assertNothingSentTo($this->retno);
    Notification::assertNothingSentTo($this->kesiswaan);
    Notification::assertNothingSentTo($offTu);

    foreach ([$this->tu, $otherTu] as $tu) {
        $notice = Notification::sent($tu, MentorAssigned::class)->sole();
        $mail = ggpMail($notice, $tu);
        $bell = $notice->toArray($tu);

        expect($notice->audience)->toBe('tu')
            ->and($mail['subject'])->toBe('Guru pembimbing diganti, surat perlu Koreksi')
            ->and($mail['lines'])->toBe([
                'Kesiswaan mengganti guru pembimbing pendaftaran National Science and Invention Fair (NSIF) 2026 (Nadia Putri Anindya): '
                .'Pak Hendra Kusuma, S.Pd., M.Si., menggantikan Bu Retno Wulandari, S.Pd. '
                .'Surat tugas guru yang sudah terbit masih memuat Bu Retno Wulandari, S.Pd., jadi Tata Usaha perlu membuat Koreksi di Arsip surat.',
            ])
            ->and($mail['action'])->toBe('Buka surat tugas')
            ->and($mail['url'])->toBe(route('tu.surat.show', ['registration' => $registration->getKey()]))
            ->and($bell['title'])->toBe('Guru pembimbing diganti, surat perlu Koreksi')
            ->and($bell['message'])->toBe($mail['lines'][0])
            // Names only: the guru letter's NIP and rank stay in the letter (D-99).
            ->and(json_encode([$mail, $bell]))->not->toContain('198001012005011001')
            ->and(json_encode([$mail, $bell]))->not->toContain('Penata')
            // No pronoun for staff (D-119).
            ->and(preg_match('/\b(kamu|anda)\b/i', implode(' ', $mail['lines'])))->toBe(0);
    }
})->with([
    'disetujui, surat sudah dibuat' => [RegistrationStatus::Disetujui],
    'surat terbit' => [RegistrationStatus::SuratTerbit],
    'berlangsung' => [RegistrationStatus::Berlangsung],
    'menunggu laporan' => [RegistrationStatus::MenungguLaporan],
]);

it('does not tell Tata Usaha when only the student letter was issued: it prints no guru', function (RegistrationStatus $status, bool $hadMentor): void {
    Notification::fake();
    $registration = ggpRegistration($status, $hadMentor ? $this->retno : null);
    $letter = ggpLetter($registration);
    $printed = $letter->fresh()->printed;

    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->hendra->getKey());

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->getKey())
        ->and($letter->fresh()->revoked_at)->toBeNull()
        ->and($letter->fresh()->printed)->toBe($printed)
        ->and(Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->sole()->properties['guru_letter_issued'])->toBeFalse()
        ->and(ChangeMentor::guruLetterIssuedAtLastChange($registration))->toBeFalse();

    // The students and the new guru are told as always; Tata Usaha has nothing to correct.
    Notification::assertSentTimes(MentorAssigned::class, 2 + 1);
    Notification::assertSentTo($this->hendra, MentorAssigned::class, fn (MentorAssigned $n): bool => $n->audience === 'guru');
    Notification::assertNothingSentTo($this->tu);
})->with([
    'surat terbit, guru diganti' => [RegistrationStatus::SuratTerbit, true],
    'surat terbit, guru diisi' => [RegistrationStatus::SuratTerbit, false],
    'berlangsung, guru diganti' => [RegistrationStatus::Berlangsung, true],
]);

it('tells each Tata Usaha account once when both letters were issued', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::SuratTerbit, $this->retno);
    ggpLetter($registration);
    ggpLetter($registration, type: LetterType::Guru);
    $otherTu = F::staff(RoleName::TataUsaha, ['name' => 'Wahyu Pratomo']);

    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->hendra->getKey());

    expect(Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->sole()->properties['guru_letter_issued'])->toBeTrue()
        ->and(ChangeMentor::guruLetterIssuedAtLastChange($registration))->toBeTrue();
    Notification::assertSentTimes(MentorAssigned::class, 2 + 1 + 2);

    foreach ([$this->tu, $otherTu] as $tu) {
        expect(Notification::sent($tu, MentorAssigned::class))->toHaveCount(1)
            ->and(Notification::sent($tu, MentorAssigned::class)->sole()->audience)->toBe('tu');
    }
});

it('never tells the actor: a Kesiswaan account that is Tata Usaha too gets no Koreksi notice of its own change', function (): void {
    Notification::fake();
    $this->kesiswaan->assignRole(RoleName::TataUsaha->value);
    $registration = ggpRegistration(RegistrationStatus::SuratTerbit, $this->retno);
    ggpLetter($registration, type: LetterType::Guru);

    app(ChangeMentor::class)->handle($this->kesiswaan->fresh(), $registration, $this->hendra->getKey());

    Notification::assertNothingSentTo($this->kesiswaan);
    Notification::assertSentTo($this->tu, MentorAssigned::class, fn (MentorAssigned $n): bool => $n->audience === 'tu');
});

it('does not tell Tata Usaha when the only guru letter was revoked', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::Disetujui, $this->retno);
    ggpLetter($registration, revoked: true, type: LetterType::Guru);

    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->hendra->getKey());

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->getKey())
        ->and(Activity::query()->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)->sole()->properties['guru_letter_issued'])->toBeFalse();
    Notification::assertNothingSentTo($this->tu);
});

it('lets a guru letter issued or corrected after the change print the new guru', function (): void {
    Notification::fake();
    F::signature();

    // Issued after the change: the first guru letter prints the guru the registration has now.
    $first = ggpRegistration(RegistrationStatus::Disetujui, $this->retno);
    app(ChangeMentor::class)->handle($this->kesiswaan, $first, $this->hendra->getKey());
    $issued = app(IssueLetters::class)->handle($this->tu, $first->fresh(), LetterType::Guru, ggpDraft('400.3.8.6/1681/2026'));

    expect($issued->fresh()->printed['content']['mentor']['name'])->toBe('Hendra Kusuma, S.Pd., M.Si.');

    // Corrected after the change: the letter keeps the old guru until the Koreksi, whose new version prints the new one.
    $second = ggpRegistration(RegistrationStatus::Disetujui, $this->retno, ['Raka Aditya Pratama']);
    $old = app(IssueLetters::class)->handle($this->tu, $second, LetterType::Guru, ggpDraft('400.3.8.6/1682/2026'));
    app(ChangeMentor::class)->handle($this->kesiswaan, $second->fresh(), $this->hendra->getKey());

    expect($old->fresh()->printed['content']['mentor']['name'])->toBe('Retno Wulandari, S.Pd.')
        ->and($old->fresh()->revoked_at)->toBeNull();
    Notification::assertSentTo($this->tu, MentorAssigned::class, fn (MentorAssigned $n): bool => $n->audience === 'tu'
        && $n->registration->is($second));

    $new = app(RevokeLetter::class)->handle($this->tu, $old->fresh(), 'Guru pembimbing diganti Kesiswaan.', ggpDraft('400.3.8.6/1682/2026'));

    expect($new->printed['content']['mentor']['name'])->toBe('Hendra Kusuma, S.Pd., M.Si.')
        ->and($old->fresh()->revoked_at)->not->toBeNull();
});

it('refuses once the report was sent, and every final status, with its own sentence', function (RegistrationStatus $status, string $message): void {
    Notification::fake();
    $registration = ggpRegistration($status, $this->retno);

    expect(fn () => app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->hendra->getKey()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['registration' => [$message]]));

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey())
        ->and(ggpChanges())->toBe(0)
        ->and($this->kesiswaan->can('assignMentor', $registration->fresh()))->toBeFalse();
    Notification::assertNothingSent();
})->with([
    'laporan dikirim' => [RegistrationStatus::LaporanDikirim, ChangeMentor::MSG_REPORTED],
    'selesai' => [RegistrationStatus::Selesai, ChangeMentor::MSG_FINISHED],
    'dibatalkan' => [RegistrationStatus::Dibatalkan, ChangeMentor::MSG_CANCELLED],
    'ditolak' => [RegistrationStatus::Ditolak, ChangeMentor::MSG_REJECTED],
]);

it('words the sentence for a sent report plainly and keeps the allowed statuses those of RevisionRules', function (): void {
    expect(ChangeMentor::MSG_REPORTED)->toBe('Laporan hasil pendaftaran ini sudah dikirim, jadi guru pembimbing tidak bisa diganti lagi.')
        ->and(ChangeMentor::STATUSES)->toBe([
            RegistrationStatus::MenungguLomba,
            RegistrationStatus::MenungguKonfirmasi,
            RegistrationStatus::MenungguKepsek,
            RegistrationStatus::Disetujui,
            RegistrationStatus::SuratTerbit,
            RegistrationStatus::Berlangsung,
            RegistrationStatus::MenungguLaporan,
        ])
        ->and(defined(ChangeMentor::class.'::MSG_LETTER'))->toBeFalse();
});

it('refuses a choice that is not a guru who can log in, the guru it already has, and no choice', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);
    $humas = F::staff(RoleName::Humas);
    $disabled = ggpMentor('Sri Lestari, S.Pd.', 'Bu');
    $disabled->forceFill(['status' => UserStatus::Disabled])->save();
    $student = $registration->leader->user;

    foreach ([
        [$humas->getKey(), ApproveRegistration::MSG_MENTOR],
        [$disabled->getKey(), ApproveRegistration::MSG_MENTOR],
        [$student->getKey(), ApproveRegistration::MSG_MENTOR],
        ['99999', ApproveRegistration::MSG_MENTOR],
        ['abc', ApproveRegistration::MSG_MENTOR],
        [$this->retno->getKey(), ChangeMentor::MSG_SAME],
        ['', ChangeMentor::MSG_CHOOSE],
        [null, ChangeMentor::MSG_CHOOSE],
    ] as [$choice, $message]) {
        expect(fn () => app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $choice))
            ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['mentor' => [$message]]));
    }

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey())
        ->and(ggpChanges())->toBe(0);
    Notification::assertNothingSent();
});

it('refuses a registration whose ketua is in the trash', function (): void {
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);
    $registration->leader->delete();

    expect(fn () => app(ChangeMentor::class)->handle($this->kesiswaan, $registration->fresh(), $this->hendra->getKey()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['registration' => [ChangeMentor::MSG_NOT_LISTED]]));
});

// Roles ------------------------------------------------------------------------------------------------------------

it('grants registration.mentor to Kesiswaan and Admin only', function (RoleName $role): void {
    expect(in_array(Permission::RegistrationMentor, $role->defaultPermissions(), true))
        ->toBe(in_array($role, [RoleName::Kesiswaan, RoleName::Admin], true));
})->with(RoleName::cases());

it('lets Kesiswaan and Admin set the guru, and refuses every other role', function (RoleName $role, bool $may): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);
    $actor = $role === RoleName::Siswa ? $registration->leader->user : F::staff($role);

    expect($actor->can('manageMentors', Registration::class))->toBe($may)
        ->and($actor->can('assignMentor', $registration))->toBe($may);

    if ($may) {
        app(ChangeMentor::class)->handle($actor, $registration, $this->hendra->getKey());
        expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->getKey());

        return;
    }

    expect(fn () => app(ChangeMentor::class)->handle($actor, $registration, $this->hendra->getKey()))->toThrow(AuthorizationException::class);
    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey());
})->with([
    'kesiswaan' => [RoleName::Kesiswaan, true],
    'admin' => [RoleName::Admin, true],
    'kepala sekolah' => [RoleName::KepalaSekolah, false],
    'tata usaha' => [RoleName::TataUsaha, false],
    'humas' => [RoleName::Humas, false],
    'guru' => [RoleName::Guru, false],
    'siswa' => [RoleName::Siswa, false],
]);

it('refuses a Kesiswaan account that cannot log in any more', function (): void {
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);
    $this->kesiswaan->forceFill(['status' => UserStatus::Disabled])->save();

    expect(fn () => app(ChangeMentor::class)->handle($this->kesiswaan->fresh(), $registration, $this->hendra->getKey()))
        ->toThrow(AuthorizationException::class);
});

// The approval's "Ditetapkan <peran>" ------------------------------------------------------------------------------

it('keeps "Ditetapkan Kepala Sekolah" for the approval only: a guru Kesiswaan set is not the approver\'s', function (): void {
    Notification::fake();
    $kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $agus = ggpMentor('Agus Santoso, S.Pd.', 'Pak');

    // Kesiswaan sets the guru before the approval, the approval keeps it.
    $before = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);
    app(ChangeMentor::class)->handle($this->kesiswaan, $before, $this->hendra->getKey());
    app(ApproveRegistration::class)->handle($kepsek, $before->fresh());
    expect(ApproveRegistration::mentorWasReplaced($before->fresh()))->toBeFalse();

    // The approver replaces the guru: "Ditetapkan Kepala Sekolah"; Kesiswaan replaces it again afterwards: no longer.
    $after = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno, ['Raka Aditya Pratama']);
    app(ApproveRegistration::class)->handle($kepsek, $after, null, $this->hendra->getKey());
    expect(ApproveRegistration::mentorWasReplaced($after->fresh()))->toBeTrue();

    app(ChangeMentor::class)->handle($this->kesiswaan, $after->fresh(), $agus->getKey());
    expect(ApproveRegistration::mentorWasReplaced($after->fresh()))->toBeFalse();
});

it('labels the change on Admin › Log audit apart from the approval', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);
    app(ChangeMentor::class)->handle($this->kesiswaan, $registration, $this->hendra->getKey());

    // The filter option covers every source; a row still says where the change was made.
    expect(AuditLog::EVENT_LABELS[ApproveRegistration::EVENT_MENTOR_CHANGED])->toBe('Guru pembimbing diganti')
        ->and(AuditLog::MENTOR_APPROVAL_LABEL)->toBe('Guru pembimbing diganti saat persetujuan');

    $this->actingAs($this->admin)->get(route('admin.audit.index'))
        ->assertOk()
        ->assertSee(ChangeMentor::AUDIT_LABEL)
        ->assertSeeHtml('value="'.ApproveRegistration::EVENT_MENTOR_CHANGED.'"')
        ->assertDontSee(AuditLog::MENTOR_APPROVAL_LABEL);
});

it('labels the guru in the approver\'s dialog by who set it: the student, or the school', function (): void {
    $kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $chosen = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);
    $set = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno, ['Raka Aditya Pratama']);
    $byKetua = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno, ['Citra Ayu Lestari']);
    $byRevision = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno, ['Hana Safira']);

    Notification::fake();
    app(ChangeMentor::class)->handle($this->kesiswaan, $set, $this->hendra->getKey());
    // The ketua's own change before the approval is still the student's choice; an approved revision is the school's.
    foreach ([[$byKetua, ChangeRegistration::MENTOR_SOURCE], [$byRevision, ApproveRevision::MENTOR_SOURCE]] as [$registration, $source]) {
        activity('registration')->performedOn($registration)->event(ApproveRegistration::EVENT_MENTOR_CHANGED)
            ->withProperties(['from' => null, 'to' => (int) $this->retno->getKey(), 'source' => $source])->log(ApproveRegistration::EVENT_MENTOR_CHANGED);
    }

    $label = fn (Registration $registration): string => Livewire::actingAs($kepsek)->test(Persetujuan::class)
        ->call('askMentor', $registration->getKey())
        ->viewData('mentorDialog')['currentLabel'];

    expect($label($chosen))->toBe('Pilihan siswa')
        ->and($label($byKetua))->toBe('Pilihan siswa')
        ->and($label($set))->toBe('Guru pembimbing saat ini')
        ->and($label($byRevision))->toBe('Guru pembimbing saat ini');

    Livewire::actingAs($kepsek)->test(Persetujuan::class)
        ->call('askMentor', $set->getKey())
        ->assertSee('Guru pembimbing saat ini:')
        ->assertDontSee('Pilihan siswa:');
});

// Screen: Kelola lomba › "N siswa" ------------------------------------------------------------------------------

it('opens the registrations of a lomba from its catalog row', function (RoleName $role): void {
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);
    $actor = $role === RoleName::Admin ? $this->admin : $this->kesiswaan;

    Livewire::actingAs($actor)->test(Index::class)
        ->assertSeeHtml('wire:click="showRegistrations(\''.$registration->competition_id.'\')"')
        ->assertSee('2 siswa')
        ->call('showRegistrations', $registration->competition_id)
        ->assertDispatched(Pendaftar::OPEN_EVENT, id: $registration->competition_id);

    Livewire::actingAs($actor)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->assertDispatched('sth-modal-open', id: Pendaftar::DIALOG)
        ->assertSee('National Science and Invention Fair (NSIF) 2026')
        ->assertSee('Tim 2 siswa: Nadia Putri Anindya (XI-4), Dimas Arya Nugroho (XI-4)')
        ->assertSee('Guru pembimbing: Bu Retno Wulandari, S.Pd.')
        ->assertSee('Menunggu persetujuan')
        ->assertSeeHtml('wire:click="askMentor(\''.$registration->getKey().'\')"')
        ->assertSee('Ganti guru pembimbing');
})->with([RoleName::Kesiswaan, RoleName::Admin]);

it('shows "Isi guru pembimbing" for a row without one, the button after the letters too, and none once the report was sent', function (RegistrationStatus $status): void {
    $empty = ggpRegistration(RegistrationStatus::Disetujui, null);
    $issued = ggpRegistration($status, $this->retno, ['Raka Aditya Pratama']);
    $issued->forceFill(['competition_id' => $empty->competition_id])->save();
    ggpLetter($issued);
    $reported = ggpRegistration(RegistrationStatus::LaporanDikirim, $this->retno, ['Citra Ayu Lestari']);
    $reported->forceFill(['competition_id' => $empty->competition_id])->save();

    Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $empty->competition_id)
        ->assertSee('Guru pembimbing bisa diisi atau diganti sampai laporan hasil dikirim.')
        ->assertSee('Guru pembimbing: belum ada')
        ->assertSee('Isi guru pembimbing')
        ->assertSeeHtml('wire:click="askMentor(\''.$empty->getKey().'\')"')
        ->assertSeeHtml('wire:click="askMentor(\''.$issued->getKey().'\')"')
        ->assertDontSeeHtml('wire:click="askMentor(\''.$reported->getKey().'\')"')
        ->assertDontSee('Surat tugas sudah dibuat.')
        ->assertDontSee('selama surat tugas belum dibuat');
})->with([
    'disetujui' => [RegistrationStatus::Disetujui],
    'surat terbit' => [RegistrationStatus::SuratTerbit],
    'berlangsung' => [RegistrationStatus::Berlangsung],
    'menunggu laporan' => [RegistrationStatus::MenungguLaporan],
]);

it('keeps the list and the button from every other role', function (RoleName $role): void {
    Livewire::actingAs(F::staff($role))->test(Pendaftar::class)->assertForbidden();
})->with([RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas, RoleName::Guru]);

it('asks in its own dialog, says who is told and never names the guru who is replaced (D-128, D-134)', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);

    $page = Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->call('askMentor', $registration->getKey())
        ->assertDispatched('sth-modal-open', id: Pendaftar::MENTOR_DIALOG)
        ->assertSet('mentorFor', $registration->getKey())
        ->assertSee('Guru pembimbing sekarang:')
        ->assertSee('Siswa diberi tahu siapa guru pembimbingnya sekarang.')
        ->assertSee('Guru yang dipilih diberi tahu bahwa ia ditetapkan sebagai guru pembimbing.')
        ->assertDontSee('diberi tahu bahwa guru pembimbing diganti')
        ->assertDontSee(ChangeMentor::TU_TOLD)
        ->assertNotDispatched('sth-toast');

    $options = $page->viewData('mentorDialog')['options'];
    expect($options)->toHaveKey((int) $this->hendra->getKey())
        ->and($options)->not->toHaveKey((int) $this->retno->getKey())
        ->and($options)->not->toHaveKey((int) $this->kesiswaan->getKey())
        ->and((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey());
    Notification::assertNothingSent();

    $page->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('changeMentor')
        ->assertDispatched('sth-modal-close', id: Pendaftar::MENTOR_DIALOG)
        ->assertDispatched('sth-toast', message: 'Guru pembimbing diganti: Pak Hendra Kusuma, S.Pd., M.Si. Siswa dan guru pembimbing yang baru diberi tahu.', tone: 'success')
        ->assertSet('mentorFor', null)
        ->assertSee('Guru pembimbing: Pak Hendra Kusuma, S.Pd., M.Si.');

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->getKey());
    Notification::assertNothingSentTo($this->retno);
});

it('adds Tata Usaha to the dialog and the toast for a registration with a guru letter', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::SuratTerbit, $this->retno);
    ggpLetter($registration, type: LetterType::Guru);

    $page = Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->call('askMentor', $registration->getKey())
        ->assertDispatched('sth-modal-open', id: Pendaftar::MENTOR_DIALOG)
        ->assertDontSee('mencabut');

    expect(ChangeMentor::TU_TOLD)->toBe('Tata Usaha diberi tahu untuk membuat Koreksi surat tugas.')
        ->and($page->viewData('mentorDialog')['notices'])->toBe([
            'Siswa diberi tahu siapa guru pembimbingnya sekarang.',
            'Guru yang dipilih diberi tahu bahwa ia ditetapkan sebagai guru pembimbing.',
            ChangeMentor::TU_TOLD,
        ]);

    $page->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('changeMentor')
        ->assertDispatched('sth-toast', message: 'Guru pembimbing diganti: Pak Hendra Kusuma, S.Pd., M.Si. Siswa dan guru pembimbing yang baru diberi tahu. '
            .ChangeMentor::TU_TOLD, tone: 'success');

    Notification::assertSentTo($this->tu, MentorAssigned::class, fn (MentorAssigned $n): bool => $n->audience === 'tu');
});

it('leaves Tata Usaha out of the dialog and the toast when only the student letter was issued', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::SuratTerbit, $this->retno);
    ggpLetter($registration);

    $page = Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->call('askMentor', $registration->getKey())
        ->assertDispatched('sth-modal-open', id: Pendaftar::MENTOR_DIALOG)
        ->assertDontSee(ChangeMentor::TU_TOLD);

    expect($page->viewData('mentorDialog')['notices'])->toBe([
        'Siswa diberi tahu siapa guru pembimbingnya sekarang.',
        'Guru yang dipilih diberi tahu bahwa ia ditetapkan sebagai guru pembimbing.',
    ]);

    $page->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('changeMentor')
        ->assertDispatched('sth-toast', message: 'Guru pembimbing diganti: Pak Hendra Kusuma, S.Pd., M.Si. Siswa dan guru pembimbing yang baru diberi tahu.', tone: 'success');

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->getKey());
    Notification::assertNothingSentTo($this->tu);
});

it('promises Tata Usaha nothing when no Tata Usaha account can log in', function (): void {
    Notification::fake();
    $this->tu->forceFill(['status' => UserStatus::Disabled])->save();
    $registration = ggpRegistration(RegistrationStatus::SuratTerbit, $this->retno);
    ggpLetter($registration, type: LetterType::Guru);

    Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->call('askMentor', $registration->getKey())
        ->assertDontSee(ChangeMentor::TU_TOLD)
        ->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('changeMentor')
        ->assertDispatched('sth-toast', message: 'Guru pembimbing diganti: Pak Hendra Kusuma, S.Pd., M.Si. Siswa dan guru pembimbing yang baru diberi tahu.', tone: 'success');

    Notification::assertNothingSentTo($this->tu);
});

it('says in the dialog that no guru hears of a registration waiting for its lomba, and inactive students get nothing', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguLomba, $this->retno, ['Nadia Putri Anindya']);
    $registration->leader->user->forceFill(['status' => UserStatus::Disabled])->save();

    Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->call('askMentor', $registration->getKey())
        ->assertSee('Akun siswa tidak aktif, jadi siswa tidak menerima notifikasi.')
        ->assertSee('Guru yang dipilih belum diberi tahu selama lomba ini menunggu verifikasi.')
        ->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('changeMentor')
        ->assertDispatched('sth-toast', message: 'Guru pembimbing diganti: Pak Hendra Kusuma, S.Pd., M.Si. Tidak ada notifikasi yang dikirim.', tone: 'success');

    Notification::assertNothingSent();
});

it('does nothing without the question and answers a forged row with 404', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);
    $elsewhere = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno, ['Raka Aditya Pratama']);

    Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('changeMentor')
        ->assertNotDispatched('sth-toast')
        ->assertNotDispatched('sth-modal-close');

    Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->call('askMentor', $elsewhere->getKey())
        ->assertNotFound();

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey())
        ->and(ggpChanges())->toBe(0);
    Notification::assertNothingSent();
});

it('asks for a guru when none was picked', function (): void {
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);

    Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->call('askMentor', $registration->getKey())
        ->call('changeMentor')
        ->assertHasErrors(['mentorChoice'])
        ->assertSet('mentorFor', $registration->getKey());

    expect(ggpChanges())->toBe(0);
});

// Stale screens ----------------------------------------------------------------------------------------------------

it('answers a guru a colleague changed since the list showed it with a toast, before and inside the dialog', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);
    $agus = ggpMentor('Agus Santoso, S.Pd.', 'Pak');

    $page = Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id);

    // Before the question: the colleague changed it after the list was drawn.
    app(ChangeMentor::class)->handle($this->admin, $registration->fresh(), $this->hendra->getKey());

    $page->call('askMentor', $registration->getKey())
        ->assertDispatched('sth-toast', message: Pendaftar::MSG_STALE_MENTOR, tone: 'warning')
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('mentorFor', null)
        ->assertSee('Guru pembimbing: Pak Hendra Kusuma, S.Pd., M.Si.');

    // Inside the dialog: changed again between the question and "Tetapkan guru".
    $page->call('askMentor', $registration->getKey())
        ->assertDispatched('sth-modal-open', id: Pendaftar::MENTOR_DIALOG);
    app(ChangeMentor::class)->handle($this->admin, $registration->fresh(), $this->retno->getKey());

    $page->set('mentorChoice', (string) $agus->getKey())
        ->call('changeMentor')
        ->assertDispatched('sth-toast', message: Pendaftar::MSG_STALE_MENTOR, tone: 'warning')
        ->assertDispatched('sth-modal-close', id: Pendaftar::MENTOR_DIALOG)
        ->assertSet('mentorFor', null);

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey());
    Notification::assertNothingSentTo($agus);
});

it('answers a report sent, a cancel and a decision after the question with a toast, never an error page', function (Closure $meanwhile, string $message): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);

    $page = Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->call('askMentor', $registration->getKey())
        ->assertDispatched('sth-modal-open', id: Pendaftar::MENTOR_DIALOG);

    $meanwhile($registration);

    $page->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('changeMentor')
        ->assertDispatched('sth-toast', message: $message.' Daftar sudah diperbarui.', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: Pendaftar::MENTOR_DIALOG)
        ->assertSet('mentorFor', null);

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey())
        ->and(ggpChanges())->toBe(0);
    Notification::assertNotSentTo($this->hendra, MentorAssigned::class);
})->with([
    'the ketua sent the report' => [fn (Registration $r) => $r->forceFill(['status' => RegistrationStatus::LaporanDikirim])->save(), ChangeMentor::MSG_REPORTED],
    'the ketua cancelled' => [fn (Registration $r) => $r->forceFill(['status' => RegistrationStatus::Dibatalkan])->save(), ChangeMentor::MSG_CANCELLED],
    'the approver rejected' => [fn (Registration $r) => $r->forceFill(['status' => RegistrationStatus::Ditolak])->save(), ChangeMentor::MSG_REJECTED],
]);

it('goes through when Tata Usaha issued the guru letter after the question, and the toast names Tata Usaha', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::Disetujui, $this->retno);

    $page = Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->call('askMentor', $registration->getKey())
        ->assertDontSee(ChangeMentor::TU_TOLD);

    ggpLetter($registration, type: LetterType::Guru);

    // What the Action wrote, not what the dialog predicted (D-109).
    $page->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('changeMentor')
        ->assertDispatched('sth-modal-close', id: Pendaftar::MENTOR_DIALOG)
        ->assertDispatched('sth-toast', message: 'Guru pembimbing diganti: Pak Hendra Kusuma, S.Pd., M.Si. Siswa dan guru pembimbing yang baru diberi tahu. '
            .ChangeMentor::TU_TOLD, tone: 'success');

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->hendra->getKey());
    Notification::assertSentTo($this->tu, MentorAssigned::class, fn (MentorAssigned $n): bool => $n->audience === 'tu');
});

it('asks again when the picked guru left the list while the dialog was open', function (): void {
    Notification::fake();
    $registration = ggpRegistration(RegistrationStatus::MenungguKepsek, $this->retno);

    $page = Livewire::actingAs($this->kesiswaan)->test(Pendaftar::class)
        ->call('open', $registration->competition_id)
        ->call('askMentor', $registration->getKey())
        ->set('mentorChoice', (string) $this->hendra->getKey());

    $this->hendra->forceFill(['status' => UserStatus::Disabled])->save();

    $page->call('changeMentor')
        ->assertHasErrors(['mentorChoice'])
        ->assertSet('mentorChoice', '')
        ->assertSet('mentorFor', $registration->getKey())
        ->assertNotDispatched('sth-toast');

    expect((int) $registration->fresh()->mentor_id)->toBe((int) $this->retno->getKey());
    Notification::assertNothingSent();
});
