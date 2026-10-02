<?php

declare(strict_types=1);

use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\RejectRegistration;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Registration;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Approval\RegistrationRejected;
use App\Notifications\Registrations\RegistrationCancelled;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
 * Who hears about a rejected or cancelled registration (lead, 2026-10-02): besides the team, the guru pembimbing
 * always, and the Kepala Sekolah when a registration they were asked to approve is cancelled. The guru notices
 * open the registration in the guru area.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->guru = F::staff(RoleName::Guru);
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Drs. Bambang Hartono, M.Pd.');
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
});

/** Team of Nadia (ketua) + Dimas, guided by the guru; $confirmed = Dimas accepted, so it waits for the Kepala Sekolah. */
function decisionTeam(object $t, bool $confirmed): Registration
{
    $registration = app(CreateRegistration::class)->handle(
        F::user($t->nadia),
        F::teamCompetition(3),
        RegistrationType::Tim,
        [$t->dimas->getKey()],
        $t->guru->id,
    );

    if ($confirmed) {
        app(ConfirmMembership::class)->handle(F::user($t->dimas), $registration);
    }

    return $registration->fresh();
}

function decisionIndividual(object $t): Registration
{
    return app(CreateRegistration::class)->handle(F::user($t->nadia), F::openCompetition(), RegistrationType::Individu, [], $t->guru->id)->fresh();
}

/**
 * The mail as plain text: PlainMailMessage stores greeting and lines with character references.
 *
 * @return array{subject: string, greeting: string, lines: list<string>, action: ?string, url: ?string}
 */
function decisionMail(object $notification, object $notifiable): array
{
    $mail = $notification->toMail($notifiable);
    expect($mail)->toBeInstanceOf(PlainMailMessage::class);

    $plain = fn (mixed $value): string => html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5);

    return [
        'subject' => (string) $mail->subject,
        'greeting' => $plain($mail->greeting),
        'lines' => array_values(array_map($plain, $mail->introLines)),
        'action' => $mail->actionText,
        'url' => $mail->actionUrl,
    ];
}

// Rejection ---------------------------------------------------------------------------------------

it('tells the guru pembimbing about a rejection, with the note, and still tells the members', function (): void {
    $registration = decisionTeam($this, confirmed: true);
    $note = 'Jadwal bentrok dengan penilaian tengah semester.';

    app(RejectRegistration::class)->handle($this->kepsek, $registration, $note);

    foreach ([$this->nadia, $this->dimas] as $member) {
        Notification::assertSentTo(F::user($member), RegistrationRejected::class, fn (RegistrationRejected $n): bool => $n->audience === 'siswa');
    }

    Notification::assertSentTo($this->guru, RegistrationRejected::class, fn (RegistrationRejected $n): bool => $n->audience === 'guru');
    Notification::assertSentToTimes($this->guru, RegistrationRejected::class, 1);
    Notification::assertNotSentTo($this->kepsek, RegistrationRejected::class); // the actor
    Notification::assertNotSentTo($this->admin, RegistrationRejected::class);

    $mail = decisionMail(Notification::sent($this->guru, RegistrationRejected::class)->first(), $this->guru);
    $lomba = $registration->competition->name;

    expect($mail['subject'])->toBe('Pendaftaran binaan tidak disetujui')
        ->and($mail['greeting'])->toBe('Yth. '.$this->guru->name.',')
        ->and($mail['lines'])->toBe([
            'Pendaftaran binaan di '.$lomba.' (Nadia Putri Anindya) tidak disetujui Kepala Sekolah.',
            'Alasan: '.$note,
        ])
        ->and($mail['action'])->toBe('Lihat pendaftaran')
        ->and($mail['url'])->toBe(route('guru.binaan.show', ['registration' => $registration->getKey()]));
});

it('keeps the rejection wording for the members and gives the guru a bell entry', function (): void {
    $registration = decisionIndividual($this);
    app(RejectRegistration::class)->handle($this->kepsek, $registration, 'Kuota sekolah sudah penuh.');

    $siswa = Notification::sent(F::user($this->nadia), RegistrationRejected::class)->first();
    $mail = decisionMail($siswa, F::user($this->nadia));
    $lomba = $registration->competition->name;

    expect($mail['subject'])->toBe('Pendaftaran lomba tidak disetujui')
        ->and($mail['greeting'])->toBe('Halo Nadia,')
        ->and($mail['lines'])->toBe(['Kepala Sekolah tidak menyetujui pendaftaranmu di '.$lomba.'.', 'Alasan: Kuota sekolah sudah penuh.'])
        ->and($mail['url'])->toBe(route('siswa.lomba-saya.show', ['registration' => $registration->getKey()]))
        ->and($siswa->toArray(F::user($this->nadia)))->toMatchArray([
            'type' => 'registration.rejected',
            'title' => 'Pendaftaran tidak disetujui',
            'message' => $lomba.'. Alasan: Kuota sekolah sudah penuh.',
        ]);

    $guru = Notification::sent($this->guru, RegistrationRejected::class)->first();

    expect($guru->toArray($this->guru))->toBe([
        'type' => 'registration.rejected',
        'registration_id' => $registration->getKey(),
        'title' => 'Pendaftaran binaan tidak disetujui',
        'message' => $lomba.' (Nadia Putri Anindya). Alasan: Kuota sekolah sudah penuh.',
        'url' => route('guru.binaan.show', ['registration' => $registration->getKey()]),
    ]);
});

it('skips a guru pembimbing who cannot log in when a registration is rejected', function (): void {
    $registration = decisionIndividual($this);
    $this->guru->forceFill(['status' => UserStatus::Disabled])->save();

    app(RejectRegistration::class)->handle($this->kepsek, $registration, 'Kuota sekolah sudah penuh.');

    Notification::assertSentTo(F::user($this->nadia), RegistrationRejected::class);
    Notification::assertNotSentTo($this->guru, RegistrationRejected::class);
});

// Cancellation ------------------------------------------------------------------------------------

it('tells the other members, the guru and the Kepala Sekolah when a registration waiting for approval is cancelled', function (): void {
    $secondPrincipal = F::staff(RoleName::KepalaSekolah, 'Dra. Sulastri, M.Pd.');
    $registration = decisionTeam($this, confirmed: true);
    expect($registration->status)->toBe(RegistrationStatus::MenungguKepsek);

    app(CancelRegistration::class)->handle(F::user($this->nadia), $registration);

    Notification::assertSentTo(F::user($this->dimas), RegistrationCancelled::class, fn (RegistrationCancelled $n): bool => $n->audience === 'siswa');
    Notification::assertSentTo($this->guru, RegistrationCancelled::class, fn (RegistrationCancelled $n): bool => $n->audience === 'guru');
    Notification::assertSentTo([$this->kepsek, $secondPrincipal], RegistrationCancelled::class, fn (RegistrationCancelled $n): bool => $n->audience === 'kepsek');
    Notification::assertNotSentTo(F::user($this->nadia), RegistrationCancelled::class); // the actor
    Notification::assertNotSentTo($this->admin, RegistrationCancelled::class);          // D-01: Admin does not approve

    foreach ([F::user($this->dimas), $this->guru, $this->kepsek] as $recipient) {
        Notification::assertSentToTimes($recipient, RegistrationCancelled::class, 1);
    }

    $lomba = $registration->competition->name;
    $guru = decisionMail(Notification::sent($this->guru, RegistrationCancelled::class)->first(), $this->guru);
    $kepsek = Notification::sent($this->kepsek, RegistrationCancelled::class)->first();
    $principal = decisionMail($kepsek, $this->kepsek);

    expect($guru['subject'])->toBe('Pendaftaran binaan dibatalkan')
        ->and($guru['greeting'])->toBe('Yth. '.$this->guru->name.',')
        ->and($guru['lines'])->toBe(['Nadia Putri Anindya membatalkan pendaftaran binaan di '.$lomba.'.'])
        ->and($guru['action'])->toBe('Lihat pendaftaran')
        ->and($guru['url'])->toBe(route('guru.binaan.show', ['registration' => $registration->getKey()]))
        ->and($principal['subject'])->toBe('Pendaftaran lomba dibatalkan')
        ->and($principal['greeting'])->toBe('Yth. Drs. Bambang Hartono, M.Pd.,')
        ->and($principal['lines'])->toBe([
            'Nadia Putri Anindya membatalkan pendaftaran '.$lomba.' (tim).',
            'Pendaftaran ini tidak perlu diputuskan lagi.',
        ])
        ->and($principal['action'])->toBe('Buka persetujuan')
        ->and($principal['url'])->toBe(route('kepsek.persetujuan.index'))
        ->and($kepsek->toArray($this->kepsek))->toBe([
            'type' => 'registration.cancelled',
            'registration_id' => $registration->getKey(),
            'title' => 'Pendaftaran lomba dibatalkan',
            'message' => 'Nadia Putri Anindya membatalkan pendaftaran '.$lomba.' (tim).',
            'url' => route('kepsek.persetujuan.index'),
        ]);
});

it('does not tell the Kepala Sekolah about a cancel before the team reached them, but tells the guru', function (): void {
    $registration = decisionTeam($this, confirmed: false);
    expect($registration->status)->toBe(RegistrationStatus::MenungguKonfirmasi);

    app(CancelRegistration::class)->handle(F::user($this->nadia), $registration);

    Notification::assertSentTo(F::user($this->dimas), RegistrationCancelled::class, fn (RegistrationCancelled $n): bool => $n->audience === 'siswa');
    Notification::assertSentTo($this->guru, RegistrationCancelled::class, fn (RegistrationCancelled $n): bool => $n->audience === 'guru');
    Notification::assertNotSentTo($this->kepsek, RegistrationCancelled::class);
    Notification::assertNotSentTo(F::user($this->nadia), RegistrationCancelled::class);
});

it('keeps the cancel wording for the members and gives the guru a bell entry', function (): void {
    $registration = decisionTeam($this, confirmed: false);
    app(CancelRegistration::class)->handle(F::user($this->nadia), $registration);

    $lomba = $registration->competition->name;
    $siswa = Notification::sent(F::user($this->dimas), RegistrationCancelled::class)->first();
    $mail = decisionMail($siswa, F::user($this->dimas));

    expect($mail['subject'])->toBe('Pendaftaran dibatalkan: '.$lomba)
        ->and($mail['greeting'])->toBe('Halo Dimas,')
        ->and($mail['lines'])->toBe(['Nadia Putri Anindya membatalkan pendaftaran '.$lomba.'.'])
        ->and($mail['action'])->toBeNull()
        ->and($siswa->toArray(F::user($this->dimas)))->toBe([
            'type' => 'registration.cancelled',
            'registration_id' => $registration->getKey(),
            'title' => 'Pendaftaran dibatalkan',
            'message' => 'Nadia Putri Anindya membatalkan pendaftaran '.$lomba.'.',
            'url' => route('siswa.lomba-saya.index'),
        ]);

    expect(Notification::sent($this->guru, RegistrationCancelled::class)->first()->toArray($this->guru))->toBe([
        'type' => 'registration.cancelled',
        'registration_id' => $registration->getKey(),
        'title' => 'Pendaftaran binaan dibatalkan',
        'message' => 'Nadia Putri Anindya membatalkan pendaftaran binaan di '.$lomba.'.',
        'url' => route('guru.binaan.show', ['registration' => $registration->getKey()]),
    ]);
});

it('tells the Kepala Sekolah about a cancelled individual registration and skips a guru who cannot log in', function (): void {
    $registration = decisionIndividual($this);
    $this->guru->forceFill(['status' => UserStatus::Disabled])->save();

    app(CancelRegistration::class)->handle(F::user($this->nadia), $registration);

    Notification::assertNotSentTo($this->guru, RegistrationCancelled::class);
    Notification::assertSentTo($this->kepsek, RegistrationCancelled::class, fn (RegistrationCancelled $n): bool => $n->audience === 'kepsek');

    $mail = decisionMail(Notification::sent($this->kepsek, RegistrationCancelled::class)->first(), $this->kepsek);
    expect($mail['lines'][0])->toBe('Nadia Putri Anindya membatalkan pendaftaran '.$registration->competition->name.' (individu).');
});

// Guru links --------------------------------------------------------------------------------------

it('opens the registration from the guru approval mail', function (): void {
    $registration = decisionIndividual($this);

    app(ApproveRegistration::class)->handle($this->kepsek, $registration);

    $notification = Notification::sent($this->guru, RegistrationApproved::class)->first();
    $url = route('guru.binaan.show', ['registration' => $registration->getKey()]);

    expect($notification->audience)->toBe('guru')
        ->and(decisionMail($notification, $this->guru)['url'])->toBe($url)
        ->and($notification->toArray($this->guru)['url'])->toBe($url);
});

it('lets the guru open a rejected and a cancelled binaan from those links', function (): void {
    F::withoutStaffTwoFactor();

    $rejected = decisionIndividual($this);
    app(RejectRegistration::class)->handle($this->kepsek, $rejected, 'Kuota sekolah sudah penuh.');

    $cancelled = decisionTeam($this, confirmed: false);
    app(CancelRegistration::class)->handle(F::user($this->nadia), $cancelled);

    foreach ([$rejected, $cancelled] as $registration) {
        $this->actingAs($this->guru)
            ->get(route('guru.binaan.show', ['registration' => $registration->getKey()]))
            ->assertOk()
            ->assertSee($registration->competition->name);
    }
});
