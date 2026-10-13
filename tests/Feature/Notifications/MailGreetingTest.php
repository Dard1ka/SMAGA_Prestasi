<?php

declare(strict_types=1);

use App\Actions\Accounts\IssueOtpCode;
use App\Actions\Accounts\SendEmailBindingNotice;
use App\Enums\RoleName;
use App\Mail\OtpCodeMail;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Accounts\AccountAlert;
use App\Notifications\Accounts\EmailBindingNotice;
use App\Notifications\Accounts\SecurityNotice;
use App\Notifications\Admin\LetterRevokedNotice;
use App\Notifications\Admin\SignatureReplaced;
use App\Notifications\Approval\RegistrationApproved;
use App\Notifications\Approval\RegistrationRejected;
use App\Notifications\Auth\PasswordWasReset;
use App\Notifications\Competitions\BinaanCompetitionChanged;
use App\Notifications\Competitions\CompetitionChanged;
use App\Notifications\Competitions\CompetitionPublished;
use App\Notifications\Competitions\CompetitionSubmitted;
use App\Notifications\Letters\LetterRevised;
use App\Notifications\Letters\LettersIssued;
use App\Notifications\Publications\AchievementPublished;
use App\Notifications\Publications\AppreciationCancelled;
use App\Notifications\Publications\AppreciationReleased;
use App\Notifications\Publications\AppreciationScheduled;
use App\Notifications\Publications\BinaanAppreciationNotice;
use App\Notifications\Publications\PublicationDue;
use App\Notifications\Registrations\InvitationsExpired;
use App\Notifications\Registrations\InvitationWithdrawn;
use App\Notifications\Registrations\MemberResponded;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Notifications\Registrations\RegistrationCancelled;
use App\Notifications\Registrations\TeamInvitation;
use App\Notifications\Reports\ReportReminder;
use App\Notifications\Reports\ReportSubmitted;
use App\Notifications\Reports\ReportWindowOpened;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use App\Notifications\Verification\BinaanReportDecided;
use App\Support\FirstName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| The lead's mail test of 2026-10-09: four greeting forms in the mails. "Halo!" where a notification set none (two
| student mails, every Admin / Kesiswaan / Humas / Kepala Sekolah notice), "Halo <first word>," which gives "Halo
| Drs.," for the Kepala Sekolah, "Halo," and "Yth. <full name>,"; the fallbacks printed "Halo Kamu," and "Halo
| Siswa,". D-129, one rule (App\Notifications\Support\MailGreeting): a student "Halo <nama depan>,", staff and an
| account without a role "Yth. <nama lengkap>,", nobody known "Halo,". Fictional people only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
});

const MAIL_GREETING_KEPSEK = 'Drs. Bambang Hartono, M.Pd.';

/** The greeting of a mail as the reader sees it (PlainMailMessage stores it with character references). */
function mailGreetingOf(object $notification, object $notifiable): string
{
    $mail = $notification->toMail($notifiable);

    expect($mail)->toBeInstanceOf(PlainMailMessage::class);

    return html_entity_decode((string) $mail->greeting, ENT_QUOTES | ENT_HTML5);
}

/**
 * One notification for every toMail() in app/Notifications, as a recipe that takes the recipient, and who it is
 * written for: 'akun' = whoever receives it (MailGreeting::for), 'siswa' = always students, 'staf' = always staff.
 *
 * @return list<array{0: Closure(User): BaseNotification, 1: 'akun'|'siswa'|'staf'}>
 */
function mailGreetingCases(): array
{
    $competition = F::teamCompetition(3, ['name' => 'Lomba Karya Tulis Ilmiah Nasional 2026']);
    $registration = Registration::factory()->team()->create([
        'competition_id' => $competition->getKey(),
        'leader_student_id' => F::student('Raka Aditya Pratama', 'XI-2')->getKey(),
    ]);
    $registrationId = (string) $registration->getKey();
    $report = Report::factory()->create();
    $signed = 'http://127.0.0.1:8080/akun/bukan-saya/01jabc?expires=1790000000&signature=0123abcd';
    $when = 'Senin, 12 Oktober 2026 pukul 07.00 WIB';

    $fixed = fn (BaseNotification $notification): Closure => fn (User $to): BaseNotification => $notification;

    return [
        // Whoever receives it
        [$fixed(new AccountAlert('account.frozen', 'Akun dibekukan', ['Nadia Putri Anindya (XI-4) dibekukan setelah 10 kali gagal masuk.'])), 'akun'],
        [$fixed(SecurityNotice::passwordChanged()), 'akun'],
        [$fixed(new PasswordWasReset), 'akun'],
        [$fixed(new SignatureReplaced('Raka')), 'akun'], // AdminNotice family
        [$fixed(new LetterRevokedNotice('Sri Handayani', 'Surat Tugas', '400.3.8/313/MRD/X/2026', '400.3.8/314/MRD/X/2026', 'OSN', 'Salah tulis')), 'akun'], // StaffNotice
        [$fixed(CompetitionPublished::for($competition, true)), 'akun'], // Notice family
        [$fixed(new CompetitionSubmitted($competition, 'Nadia Putri Anindya')), 'akun'],
        [$fixed(new PublicationDue('01jpub', 'Nadia Putri Anindya', 'Juara II', 'OPSI 2026', 'Instagram', '12 Okt 2026, 09.00')), 'akun'],
        [$fixed(new ReportSubmitted('01jrep', 'OPSI 2026', 'Nadia Putri Anindya', 'Juara II', false)), 'akun'],
        // The Action passes the greeting of the account owner: the mail itself goes to a bare address.
        [fn (User $to): BaseNotification => new EmailBindingNotice(EmailBindingNotice::BOUND, (string) FirstName::of($to->name), 'n•••@gmail.com', $signed, 7, MailGreeting::for($to)), 'akun'],

        // Always students
        [$fixed(new ReportReminder($registrationId, 'OPSI 2026', '8 Nov 2026', 3)), 'siswa'],
        [$fixed(new ReportWindowOpened($registrationId, 'OPSI 2026')), 'siswa'],
        [$fixed(CompetitionChanged::for($competition, $registrationId, ['Babak final sekarang 20 November 2026.'])), 'siswa'],
        [$fixed(new AchievementPublished('01jpub', '01jach', 'OPSI 2026', 'Juara II', 'Instagram')), 'siswa'],
        [$fixed(new AppreciationScheduled('01japp', 'Upacara Senin', $when, 'OPSI 2026', 'Juara II')), 'siswa'],
        [$fixed(new AppreciationReleased('Upacara Senin', $when, 'Juara II OPSI 2026')), 'siswa'],
        [$fixed(new AppreciationCancelled('Upacara Senin', $when, ['Juara II OPSI 2026'])), 'siswa'],
        [$fixed(new TeamInvitation($registration)), 'siswa'],
        [$fixed(new MemberResponded($registration, 'Dimas Arya Saputra', false)), 'siswa'],
        [$fixed(new InvitationWithdrawn($registration)), 'siswa'],
        [$fixed(new InvitationsExpired($registration, 1, true, 2)), 'siswa'],
        [$fixed(RegistrationCancelled::for($registration, 'siswa')), 'siswa'],
        [$fixed(RegistrationApproved::for($registration, 'siswa')), 'siswa'],
        [$fixed(RegistrationRejected::for($registration, 'siswa')), 'siswa'],
        [$fixed(LettersIssued::for($registration, 'siswa')), 'siswa'],
        [$fixed(new LetterRevised($registration, 'siswa', '400.3.8/313/MRD/X/2026', '400.3.8/320/MRD/X/2026', null)), 'siswa'],

        // Always staff
        [$fixed(BinaanCompetitionChanged::for($competition, ['Babak final sekarang 20 November 2026.'], $registrationId)), 'staf'],
        [$fixed(new BinaanAppreciationNotice(BinaanAppreciationNotice::SCHEDULED, 'Upacara Senin', $when, ['Nadia Putri Anindya (Juara II OPSI 2026)'])), 'staf'],
        [$fixed(BinaanReportDecided::rejected($report, 'OPSI 2026', 'Bukti tidak terbaca')), 'staf'],
        [$fixed(new RegistrationAwaitingPrincipal($registration)), 'staf'],
        [$fixed(RegistrationCancelled::for($registration, 'guru')), 'staf'],
        [$fixed(RegistrationCancelled::for($registration, 'kepsek')), 'staf'],
        [$fixed(RegistrationApproved::for($registration, 'guru')), 'staf'],
        [$fixed(RegistrationApproved::for($registration, 'guru', true, 'Bu Retno Wulandari, S.Pd.')), 'staf'],
        [$fixed(RegistrationApproved::for($registration, 'tu')), 'staf'],
        [$fixed(RegistrationRejected::for($registration, 'guru')), 'staf'],
        [$fixed(LettersIssued::for($registration, 'guru')), 'staf'],
        [$fixed(new LetterRevised($registration, 'guru', '400.3.8.6/1681/2026', '400.3.8.6/1690/2026', null)), 'staf'],
    ];
}

// The rule ---------------------------------------------------------------------------------------------------------

it('greets a student by the name the student is called by', function (string $name, string $expected): void {
    expect(MailGreeting::for(F::user(F::student($name))))->toBe($expected)
        ->and(MailGreeting::student($name))->toBe($expected);
})->with([
    ['Nadia Putri Anindya', 'Halo Nadia,'],
    ['M. Rizky Pratama', 'Halo Rizky,'],
    ["Ma'ruf Hidayat", "Halo Ma'ruf,"],
]);

it('greets every staff role formally with the full name, never with a pronoun or a first word', function (RoleName $role): void {
    $staff = F::staff($role, MAIL_GREETING_KEPSEK);

    expect(MailGreeting::for($staff))->toBe('Yth. Drs. Bambang Hartono, M.Pd.,')
        ->and(MailGreeting::staff($staff))->toBe('Yth. Drs. Bambang Hartono, M.Pd.,');
})->with([
    'guru' => [RoleName::Guru],
    'kesiswaan' => [RoleName::Kesiswaan],
    'kepala sekolah' => [RoleName::KepalaSekolah],
    'tata usaha' => [RoleName::TataUsaha],
    'humas' => [RoleName::Humas],
    'admin' => [RoleName::Admin],
]);

it('greets an account that has no role yet like staff (a new privileged account waits for its role)', function (): void {
    $pending = User::factory()->create(['name' => 'Sri Handayani'])->fresh();

    expect($pending->getRoleNames()->all())->toBe([])
        ->and(MailGreeting::for($pending))->toBe('Yth. Sri Handayani,');
});

it('greets a student record that lost the siswa role still as a student', function (): void {
    $user = F::user(F::student('Dimas Arya Saputra'));
    $user->syncRoles([]);

    expect(MailGreeting::for($user->fresh()))->toBe('Halo Dimas,');
});

it('falls back to the neutral greeting: empty name, a bare address, anything that is no account', function (): void {
    $nameless = F::staff(RoleName::Guru, 'Sementara');
    $nameless->forceFill(['name' => '   '])->save();

    expect(MailGreeting::NEUTRAL)->toBe('Halo,')
        ->and(MailGreeting::for($nameless->fresh()))->toBe('Halo,')
        ->and(MailGreeting::for(Notification::route('mail', 'seseorang@contoh.test')))->toBe('Halo,')
        ->and(MailGreeting::for(new AnonymousNotifiable))->toBe('Halo,')
        ->and(MailGreeting::for(new stdClass))->toBe('Halo,')
        ->and(MailGreeting::student(null))->toBe('Halo,')
        ->and(MailGreeting::student(''))->toBe('Halo,')
        ->and(MailGreeting::staff(null))->toBe('Halo,')
        ->and(MailGreeting::staff("  \n "))->toBe('Halo,')
        ->and(MailGreeting::student(new AnonymousNotifiable))->toBe('Halo,')
        ->and(MailGreeting::staff(new AnonymousNotifiable))->toBe('Halo,');
});

it('keeps a name on one line', function (): void {
    expect(MailGreeting::staff("Drs.  Bambang\nHartono,\tM.Pd."))->toBe('Yth. Drs. Bambang Hartono, M.Pd.,')
        ->and(MailGreeting::student("Nadia\r\nPutri"))->toBe('Halo Nadia,');
});

it('works for accounts that were loaded together (strict models forbid lazy loading)', function (): void {
    F::student('Nadia Putri Anindya');
    F::student('Dimas Arya Saputra', 'XI-4');
    F::staff(RoleName::KepalaSekolah, MAIL_GREETING_KEPSEK);
    F::staff(RoleName::Kesiswaan, 'Siti Rahmawati, S.Pd.');

    $greetings = User::query()->orderBy('id')->get()->map(fn (User $user): string => MailGreeting::for($user))->all();

    expect($greetings)->toBe(['Halo Nadia,', 'Halo Dimas,', 'Yth. Drs. Bambang Hartono, M.Pd.,', 'Yth. Siti Rahmawati, S.Pd.,']);
});

// Every notification ---------------------------------------------------------------------------------------------------

it('greets by the one rule in every notification mail, for a student and for the Kepala Sekolah', function (): void {
    $nadia = F::user(F::student('Nadia Putri Anindya'));
    $kepsek = F::staff(RoleName::KepalaSekolah, MAIL_GREETING_KEPSEK);

    $expected = [
        'akun' => ['Halo Nadia,', 'Yth. Drs. Bambang Hartono, M.Pd.,'],
        // Written for one audience: the notification decides, whoever a test hands it to.
        'siswa' => ['Halo Nadia,', 'Halo Bambang,'],
        'staf' => ['Yth. Nadia Putri Anindya,', 'Yth. Drs. Bambang Hartono, M.Pd.,'],
    ];
    $never = ['', 'Halo!', 'Halo Drs.,', 'Halo Kamu,', 'Halo Siswa,', 'Yth. ,', 'Yth. Kepala Sekolah,'];

    foreach (mailGreetingCases() as [$make, $audience]) {
        foreach ([$nadia, $kepsek] as $index => $to) {
            $notification = $make($to);
            $greeting = mailGreetingOf($notification, $to);

            expect($greeting)->toBe($expected[$audience][$index], $notification::class.' to '.$to->name)
                ->and(in_array($greeting, $never, true))->toBeFalse();
        }
    }
});

it('covers every toMail() in app/Notifications with the matrix above', function (): void {
    $nadia = F::user(F::student('Nadia Putri Anindya'));
    $covered = [];

    foreach (mailGreetingCases() as [$make]) {
        $covered[(new ReflectionMethod($make($nadia), 'toMail'))->getDeclaringClass()->getName()] = true;
    }

    $missing = [];
    $concrete = 0;

    foreach ((new Finder)->files()->in(app_path('Notifications'))->name('*.php') as $file) {
        $class = 'App\\Notifications\\'.str_replace(['/', '.php'], ['\\', ''], str_replace('\\', '/', $file->getRelativePathname()));

        if (! class_exists($class) || ! is_subclass_of($class, BaseNotification::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $concrete++;
        $declaring = (new ReflectionMethod($class, 'toMail'))->getDeclaringClass()->getName();

        if (! isset($covered[$declaring])) {
            $missing[] = $class.' (toMail of '.$declaring.')';
        }
    }

    // A new notification with a toMail() of its own belongs in mailGreetingCases().
    expect($missing)->toBe([])
        ->and($concrete)->toBeGreaterThanOrEqual(46);
});

it('builds no greeting by hand and sets no sign-off anywhere', function (): void {
    $offenders = [];
    $patterns = [
        "greeting built by hand ('Halo '. / 'Yth. '.)" => "~'(Halo|Yth\\.) '\\s*\\.~",
        'first word of the name (strtok)' => '~strtok\(\s*trim\(~',
        'first word of the name (explode)' => "~explode\\(' '\\)->first\\(\\)~",
        'sign-off (->salutation)' => '~->salutation\(~',
    ];

    foreach ((new Finder)->files()->in([app_path('Notifications'), app_path('Mail'), app_path('Actions/Accounts'), app_path('Livewire/Siswa')])->name('*.php') as $file) {
        if ($file->getFilename() === 'MailGreeting.php') {
            continue;
        }

        foreach ($patterns as $what => $pattern) {
            if (preg_match($pattern, $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname().': '.$what;
            }
        }
    }

    expect($offenders)->toBe([]);
});

// The two mails that are not sent to an account -----------------------------------------------------------------------

it('passes the greeting of the account owner to the email binding notice', function (User $owner, string $greeting, string $firstName): void {
    Notification::fake();

    app(SendEmailBindingNotice::class)->handle($owner, EmailBindingNotice::BOUND, 'pemilik@contoh.test', 'pemilik@contoh.test');

    Notification::assertSentOnDemand(EmailBindingNotice::class, function (EmailBindingNotice $notice, array $channels, AnonymousNotifiable $to) use ($greeting, $firstName): bool {
        return $notice->greeting === $greeting
            && $notice->firstName === $firstName
            && mailGreetingOf($notice, $to) === $greeting
            // The text names the owner by the first name only, staff too ("atas nama Drs." before).
            && $notice->lines()[0] === 'Email ini baru saja dihubungkan ke akun SMAGA Talent Hub atas nama '.$firstName.'.';
    });
})->with([
    'student' => [fn () => F::user(F::student('Nadia Putri Anindya')), 'Halo Nadia,', 'Nadia'],
    'Kepala Sekolah' => [fn () => F::staff(RoleName::KepalaSekolah, MAIL_GREETING_KEPSEK), 'Yth. Drs. Bambang Hartono, M.Pd.,', 'Bambang'],
]);

it('leaves "atas nama" out of the binding notice when the owner has no name', function (): void {
    $notice = new EmailBindingNotice(EmailBindingNotice::CHANGED_NEW, '', 'n•••@gmail.com', 'http://127.0.0.1:8080/akun/bukan-saya/01jabc', 7);

    expect($notice->lines()[0])->toBe('Email ini baru saja dipasang di akun SMAGA Talent Hub.')
        ->and(mailGreetingOf($notice, new AnonymousNotifiable))->toBe('Halo,');
});

it('passes the greeting of the account to the OTP mail', function (User $owner, string $greeting): void {
    Mail::fake();

    expect(app(IssueOtpCode::class)->handle($owner, OtpCodeMail::PURPOSE_VERIFY_EMAIL, 'pemilik@contoh.test'))->toBeTrue();

    Mail::assertQueued(OtpCodeMail::class, fn (OtpCodeMail $mail): bool => $mail->greeting === $greeting && $mail->hasTo('pemilik@contoh.test'));
})->with([
    'student' => [fn () => F::user(F::student('M. Rizky Pratama')), 'Halo Rizky,'],
    'Kepala Sekolah' => [fn () => F::staff(RoleName::KepalaSekolah, MAIL_GREETING_KEPSEK), 'Yth. Drs. Bambang Hartono, M.Pd.,'],
]);
