<?php

declare(strict_types=1);

/*
 * E2E only (tests/E2E/compose.e2e.yaml): the "email scenario". Drives the whole school workflow through the real
 * Action classes (app/Actions/**), lets the real queue worker send the real mails to Mailpit and prints, per step,
 * which role received which email next to the recipients the workflow expects (OK / MISSING / UNEXPECTED).
 *
 * Nothing is faked: the script never calls notify() itself. It only arranges time where a human would wait
 * (competition dates moved into the past before the timeline commands; a real sleep for the 60 s OTP cooldown).
 * Every mail body is also scanned for data that must never be mailed (initial password and its old pattern, every NIS/NISN,
 * birth dates, the passwords typed in this run) and for a broken template (empty part, unrendered Blade, HTML
 * entities shown literally).
 *
 * Steps: 0 setup (second Humas) · 1 import + activation · 2 lomba uploads + verification (incl. a lomba name and a
 * note written as Markdown: no link or image may reach the HTML part) · 3 registration (team, invitations,
 * individual, decline, cancel before and after the team reached the Kepala Sekolah) · 4 Kepala Sekolah (approve,
 * reject: the guru pembimbing is told too) · 5 Tata Usaha letters + corrections · 6 timeline commands ·
 * 7 laporan hasil + verification · 8 Humas (a scheduled publication only reminds Humas at its time; the student is
 * mailed when Humas marks it published, D-122) · 9 talent · 10 account + Admin events (a frozen account gets no mail
 * and no reset code) · 11 the production mail rule, with OTP test mode switched off inside this process for those
 * steps only: an address nobody verified gets account notices but no work mail.
 *
 * Refuses to run unless APP_ENV=local and STH_E2E=1 (set only on the app service of the isolated E2E project), so
 * it can never touch the dev database or any server. Needs a fresh E2E database (setup-app.sh) for every run.
 *
 *   docker compose -f compose.yaml -f tests/E2E/compose.e2e.yaml up -d --no-build --wait mysql app web mailpit
 *   docker compose -f compose.yaml -f tests/E2E/compose.e2e.yaml exec -T app sh tests/E2E/support/setup-app.sh
 *   docker compose -f compose.yaml -f tests/E2E/compose.e2e.yaml exec -T app php tests/E2E/support/email-scenario.php
 *   docker compose -f compose.yaml -f tests/E2E/compose.e2e.yaml down -v
 *
 * Exit code 1 when an expected email is missing, an email nobody expected arrives, a step or one of its checks
 * fails, a mail leaks sensitive data or a job failed.
 * env: MAILPIT_API (default http://mailpit:8025)
 */

use App\Actions\Accounts\ChangeEmail;
use App\Actions\Accounts\ChangePassword;
use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\FreezeAccountFromNotice;
use App\Actions\Accounts\GenerateTemporaryPassword;
use App\Actions\Accounts\ImportStudents;
use App\Actions\Accounts\IssueOtpCode;
use App\Actions\Accounts\RecordFailedLogin;
use App\Actions\Accounts\RequestPasswordResetCode;
use App\Actions\Accounts\ResetPasswordWithCode;
use App\Actions\Accounts\SetNewPassword;
use App\Actions\Accounts\VerifyOtpCode;
use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\SubmitCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Actions\Competitions\VerifyCompetition;
use App\Actions\Letters\IssueLetters;
use App\Actions\Letters\LetterDraft;
use App\Actions\Letters\RevokeLetter;
use App\Actions\Letters\SendLetters;
use App\Actions\Publications\CreateAppreciationEvent;
use App\Actions\Publications\PublishPublication;
use App\Actions\Publications\RecordAppreciation;
use App\Actions\Publications\RemindDuePublication;
use App\Actions\Publications\SchedulePublication;
use App\Actions\Registrations\ApproveRegistration;
use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\DeclineMembership;
use App\Actions\Registrations\InviteMembers;
use App\Actions\Registrations\RejectRegistration;
use App\Actions\Reports\RejectReport;
use App\Actions\Reports\RequestReportRevision;
use App\Actions\Reports\SubmitReport;
use App\Actions\Reports\SubmitReportData;
use App\Actions\Reports\VerifyReport;
use App\Actions\Roles\RequestRoleChange;
use App\Actions\Settings\PublishWeightVersion;
use App\Actions\Settings\ReplaceSignatureImage;
use App\Actions\Talent\ApplyTalent;
use App\Actions\Talent\MoveTalentStage;
use App\Actions\Talent\RejectTalentApplication;
use App\Actions\Talent\SaveTalentProfile;
use App\Actions\Templates\LetterTemplate;
use App\Actions\Templates\UpdateLetterTemplate;
use App\Actions\Users\CreateStaffAccount;
use App\Actions\Users\ResetStaffAccount;
use App\Domain\Accounts\InitialPassword;
use App\Enums\CompetitionStatus;
use App\Enums\LetterType;
use App\Enums\PublicationStatus;
use App\Enums\RegistrationType;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\TalentTypeCode;
use App\Jobs\RemindDuePublications;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\Letter;
use App\Models\Publication;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use App\Models\User;
use App\Models\WeightVersion;
use App\Notifications\Publications\PublicationDue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Small harness: runs a step, drains the queue with the real worker, reads the new Mailpit messages and compares
 * them with the expected recipients.
 */
final class EmailScenario
{
    public const ROLES = ['siswa', 'guru', 'kesiswaan', 'kepala_sekolah', 'tata_usaha', 'humas', 'admin'];

    /** @var array<string, true> Mailpit message ids already reported */
    private array $seen = [];

    /** @var array<string, string> address => role, for addresses the database cannot resolve (yet/any more) */
    private array $roleOf = [];

    /** @var list<array{step: string, role: string, to: string, subject: string}> */
    private array $received = [];

    /** @var list<string> */
    private array $missing = [];

    /** @var list<string> */
    private array $unexpected = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $leaks = [];

    /** @var list<string> passwords typed in this run: must never show up in a mail (never printed) */
    private array $secrets = [];

    /** Mails scanned, mails in which the control phrase (header and footer sentence) was found, strings searched in the last scan. */
    private int $scanned = 0;

    private int $controlHits = 0;

    private int $needleCount = 0;

    public function __construct(private readonly string $mailpit) {}

    public function out(string $line = ''): void
    {
        fwrite(STDOUT, $line."\n");
    }

    public function note(string $line): void
    {
        $this->out('    · '.$line);
    }

    public function problem(string $step, string $message): void
    {
        $this->errors[] = "[{$step}] {$message}";
        $this->out('    => ERROR: '.$message);
    }

    public function knownAs(string $address, string $role): void
    {
        $this->roleOf[mb_strtolower($address)] = $role;
    }

    public function secret(string $value): void
    {
        $this->secrets[] = $value;
    }

    public function clearMailbox(): void
    {
        Http::delete($this->mailpit.'/api/v1/messages')->throw();
        $this->seen = [];
    }

    /** Run the real queue worker (same queues as the `queue` service) until nothing is left. */
    public function drain(): void
    {
        for ($round = 0; $round < 5; $round++) {
            Artisan::call('queue:work', [
                '--queue' => 'otp,mail,default',
                '--stop-when-empty' => true,
                '--tries' => 3,
                '--sleep' => 0,
                '--memory' => 1024,
                '--timeout' => 300,
            ]);

            if (DB::table('jobs')->where('available_at', '<=', time())->doesntExist()) {
                return;
            }
        }
    }

    /**
     * Every message in the mailbox, newest first.
     *
     * @return list<array{id: string, to: list<string>, subject: string}>
     */
    public function mailbox(): array
    {
        $messages = [];

        for ($start = 0; ; $start += 100) {
            $page = Http::get($this->mailpit.'/api/v1/messages', ['start' => $start, 'limit' => 100])->throw()->json('messages') ?? [];

            foreach ($page as $message) {
                $messages[] = [
                    'id' => (string) $message['ID'],
                    'to' => array_map(fn (array $to): string => mb_strtolower((string) $to['Address']), $message['To'] ?? []),
                    'subject' => (string) ($message['Subject'] ?? ''),
                ];
            }

            if (count($page) < 100) {
                return $messages;
            }
        }
    }

    /** @return array<string, mixed> full Mailpit message (Text, HTML, Attachments, …) */
    public function full(string $id): array
    {
        return (array) Http::get($this->mailpit.'/api/v1/message/'.$id)->throw()->json();
    }

    /**
     * The newest mail to $address with that subject (queue drained first).
     *
     * @return array<string, mixed> full Mailpit message
     */
    public function newest(string $address, string $subject): array
    {
        $this->drain();

        foreach ($this->mailbox() as $message) {
            if ($message['subject'] === $subject && in_array(mb_strtolower($address), $message['to'], true)) {
                return $this->full($message['id']);
            }
        }

        throw new RuntimeException("No \"{$subject}\" mail arrived for {$address}.");
    }

    /** Text part of the newest mail to $address with that subject. */
    public function textOf(string $address, string $subject): string
    {
        return (string) ($this->newest($address, $subject)['Text'] ?? '');
    }

    /** What a reader sees in an HTML part: tags removed, entities decoded, whitespace collapsed. */
    public static function visible(string $html): string
    {
        $body = (string) preg_replace('~<(style|head)\b.*?</\1>~is', ' ', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)));
    }

    /**
     * A normal notification mail still renders: greeting and line in both parts, the action as a real button.
     * Call inside a step after the Action; a failed check fails the run.
     */
    public function assertRendered(string $step, string $address, string $subject, string $greeting, string $line, ?string $button = null, ?string $urlPart = null): void
    {
        $mail = $this->newest($address, $subject);
        $html = (string) ($mail['HTML'] ?? '');
        $text = (string) preg_replace('/\s+/u', ' ', (string) ($mail['Text'] ?? ''));
        $visible = self::visible($html);
        $failed = [];

        foreach (['greeting' => $greeting, 'line' => $line] as $what => $value) {
            if (! str_contains($visible, $value)) {
                $failed[] = $what.' missing in the HTML part';
            }

            if (! str_contains($text, $value)) {
                $failed[] = $what.' missing in the text part';
            }
        }

        if ($button !== null) {
            $found = false;

            if (preg_match_all('~<a\b([^>]*)>(.*?)</a>~is', $html, $links, PREG_SET_ORDER) > 0) {
                foreach ($links as [, $attributes, $label]) {
                    $found = $found || (str_contains($attributes, 'class="button')
                        && trim(strip_tags($label)) === $button
                        && preg_match('~href="[^"]*'.preg_quote((string) $urlPart, '~').'~', $attributes) === 1);
                }
            }

            if (! $found) {
                $failed[] = 'button "'.$button.'" missing in the HTML part';
            }

            if (! str_contains($text, $button.': ')) {
                $failed[] = 'action "'.$button.'" missing in the text part';
            }
        }

        if ($failed === []) {
            $this->note(sprintf('template check "%s": greeting "%s", line%s rendered in HTML + text', $subject, $greeting, $button !== null ? ' and button "'.$button.'"' : ''));

            return;
        }

        $this->problem($step, sprintf('template check "%s" to %s: %s', $subject, $address, implode('; ', $failed)));
    }

    /** The 6-digit code the user would read in the OTP mail. */
    public function codeFromMail(string $address, string $subject): string
    {
        if (preg_match('/^\s*(\d{6})\s*$/m', $this->textOf($address, $subject), $match) !== 1) {
            throw new RuntimeException("The \"{$subject}\" mail for {$address} has no 6-digit code.");
        }

        return $match[1];
    }

    public function role(string $address): string
    {
        $address = mb_strtolower($address);

        if (isset($this->roleOf[$address])) {
            return $this->roleOf[$address];
        }

        $role = User::query()->where('email', $address)->first()?->getRoleNames()->first();

        return is_string($role) ? $role : 'tidak dikenal';
    }

    /**
     * @param  Closure(): mixed  $action
     * @param  list<array{0: string, 1: string}>|Closure(): list<array{0: string, 1: string}>  $expected  [address, subject];
     *                                                                                                    a subject ending in * is a prefix
     */
    public function step(string $id, string $title, Closure $action, array|Closure $expected = []): void
    {
        $this->out();
        $this->out("[{$id}] {$title}");

        try {
            $action();
        } catch (ValidationException $e) {
            $this->problem($id, 'ValidationException '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            $this->problem($id, $e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')');
        }

        $this->drain();

        try {
            $expected = $expected instanceof Closure ? $expected() : $expected;
        } catch (Throwable) {
            $expected = [];
        }

        /** @var list<array{to: string, subject: string, matched: bool}> $arrived */
        $arrived = [];
        $needles = $this->needles();

        foreach (array_reverse($this->mailbox()) as $message) {
            if (isset($this->seen[$message['id']])) {
                continue;
            }

            $this->seen[$message['id']] = true;

            foreach ($message['to'] as $to) {
                $role = $this->role($to);
                $this->out(sprintf('    %-15s %-36s %s', $role, $to, $message['subject']));
                $this->received[] = ['step' => $id, 'role' => $role, 'to' => $to, 'subject' => $message['subject']];
                $arrived[] = ['to' => $to, 'subject' => $message['subject'], 'matched' => false];
            }

            $this->scan($id, $message, $needles);
        }

        if ($arrived === []) {
            $this->out('    (no email)');
        }

        $missing = [];

        foreach ($expected as [$address, $subject]) {
            $hit = null;

            foreach ($arrived as $index => $mail) {
                if (! $mail['matched'] && $mail['to'] === mb_strtolower($address) && self::subjectMatches($subject, $mail['subject'])) {
                    $hit = $index;
                    break;
                }
            }

            if ($hit === null) {
                $missing[] = sprintf('%s <%s> "%s"', $this->role($address), $address, $subject);
            } else {
                $arrived[$hit]['matched'] = true;
            }
        }

        $unexpected = array_values(array_map(
            fn (array $mail): string => sprintf('%s <%s> "%s"', $this->role($mail['to']), $mail['to'], $mail['subject']),
            array_filter($arrived, fn (array $mail): bool => ! $mail['matched']),
        ));

        foreach ($missing as $line) {
            $this->missing[] = "[{$id}] {$line}";
            $this->out('    => MISSING: '.$line);
        }

        foreach ($unexpected as $line) {
            $this->unexpected[] = "[{$id}] {$line}";
            $this->out('    => UNEXPECTED: '.$line);
        }

        if ($missing === [] && $unexpected === []) {
            $this->out(sprintf('    => OK (%d expected, %d arrived)', count($expected), count($arrived)));
        }
    }

    /** Summary per role + failed jobs; returns the process exit code. */
    public function summary(): int
    {
        $this->out();
        $this->out(str_repeat('=', 100));
        $this->out('SUMMARY PER ROLE (emails received in Mailpit)');
        $this->out(str_repeat('=', 100));

        $roles = array_values(array_unique([...self::ROLES, ...array_column($this->received, 'role')]));

        foreach ($roles as $role) {
            $mails = array_values(array_filter($this->received, fn (array $mail): bool => $mail['role'] === $role));
            $this->out(sprintf('%s: %d email', $role, count($mails)));

            $subjects = [];

            foreach ($mails as $mail) {
                $subjects[$mail['subject']][] = $mail['step'];
            }

            foreach ($subjects as $subject => $steps) {
                $this->out(sprintf('    %2dx  %s   [step %s]', count($steps), $subject, implode(', ', array_unique($steps))));
            }
        }

        $failed = DB::table('failed_jobs')->orderBy('id')->get();
        $pending = DB::table('jobs')->count();

        $this->out();
        $this->out(sprintf(
            'LEAK SCAN: %d mails (subject + text + HTML) × %d strings (initial password and its old pattern, every NIS/NISN, birth dates, passwords typed in this run); control phrase found in %d/%d mails.',
            $this->scanned,
            $this->needleCount,
            $this->controlHits,
            $this->scanned,
        ));
        $this->out(sprintf(
            'TOTAL: %d email · MISSING %d · UNEXPECTED %d · step errors %d · leaks %d · failed_jobs %d · jobs still queued %d',
            count($this->received),
            count($this->missing),
            count($this->unexpected),
            count($this->errors),
            count($this->leaks),
            $failed->count(),
            $pending,
        ));

        foreach (['MISSING' => $this->missing, 'UNEXPECTED' => $this->unexpected, 'ERROR' => $this->errors, 'LEAK' => $this->leaks] as $label => $lines) {
            foreach ($lines as $line) {
                $this->out("  {$label}: {$line}");
            }
        }

        foreach ($failed as $job) {
            $name = json_decode((string) $job->payload, true)['displayName'] ?? '?';
            $this->out(sprintf('  FAILED JOB #%d [%s] %s', $job->id, $job->queue, $name));
            $this->out('    '.str_replace("\n", "\n    ", mb_substr((string) $job->exception, 0, 900)));
        }

        $bad = $this->missing !== [] || $this->unexpected !== [] || $this->errors !== [] || $this->leaks !== [] || $failed->isNotEmpty();
        $this->out($bad ? 'RESULT: FAILED' : 'RESULT: PASSED');

        return $bad ? 1 : 0;
    }

    private static function subjectMatches(string $expected, string $actual): bool
    {
        return str_ends_with($expected, '*')
            ? str_starts_with($actual, substr($expected, 0, -1))
            : $expected === $actual;
    }

    /**
     * Strings that must never appear in a mail: label => needles. Values are never printed.
     *
     * @return array<string, list<string>>
     */
    private function needles(): array
    {
        $needles = [
            'initial password' => [app(InitialPassword::class)->make()],
            'initial password, old pattern' => [InitialPassword::LEGACY_PREFIX, rtrim(InitialPassword::LEGACY_PREFIX, '_')],
            'a password typed in this run' => $this->secrets,
            'full NIS/NISN' => [],
            'birth date' => [],
        ];

        foreach (Student::withTrashed()->get(['nis', 'nisn', 'birth_date']) as $student) {
            $needles['full NIS/NISN'][] = (string) $student->nis;
            $needles['full NIS/NISN'][] = (string) $student->nisn;

            if ($student->birth_date !== null) {
                $date = CarbonImmutable::parse((string) $student->getRawOriginal('birth_date'));

                foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'dmY', 'dmy'] as $format) {
                    $needles['birth date'][] = $date->format($format);
                }
            }
        }

        return $needles;
    }

    /**
     * Does $value occur in the (lower-cased) mail text? The initial password only counts as a whole token: it is
     * a short number, and a 6-digit OTP code that happens to contain it is not a leak. Every other label is a
     * plain substring match.
     */
    private function found(string $label, string $haystack, string $value): bool
    {
        $value = mb_strtolower($value);

        if ($label === 'initial password') {
            return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1;
        }

        return str_contains($haystack, $value);
    }

    /**
     * @param  array{id: string, to: list<string>, subject: string}  $message
     * @param  array<string, list<string>>  $needles
     */
    private function scan(string $step, array $message, array $needles): void
    {
        $full = $this->full($message['id']);
        $text = (string) ($full['Text'] ?? '');
        $html = (string) ($full['HTML'] ?? '');
        $haystack = mb_strtolower($message['subject']."\n".$text."\n".html_entity_decode($html));

        $this->scanned++;
        $this->controlHits += str_contains($haystack, 'smaga talent hub') ? 1 : 0;
        $this->needleCount = array_sum(array_map(count(...), $needles));

        if (trim($text) === '' || trim($html) === '' || preg_match('/\{\{|\}\}|@(component|slot|lang|if|foreach|endif)\b/', $text.$html) === 1) {
            $this->problem($step, sprintf('broken mail template: "%s" to %s (empty part or unrendered Blade)', $message['subject'], implode(', ', $message['to'])));
        }

        // PlainMailMessage writes punctuation as character references: they must never reach the reader as text.
        if (preg_match('/&amp;#\d+;/', $html) === 1 || preg_match('/&#\d+;|&amp;|&lt;|&gt;/', $text.$message['subject']) === 1) {
            $this->problem($step, sprintf('broken mail template: "%s" to %s shows HTML entities as text', $message['subject'], implode(', ', $message['to'])));
        }

        foreach ($needles as $label => $values) {
            foreach ($values as $value) {
                if ($value !== '' && $this->found($label, $haystack, $value)) {
                    $line = sprintf('[%s] "%s" to %s contains %s', $step, $message['subject'], implode(', ', $message['to']), $label);
                    $this->leaks[] = $line;
                    $this->out('    => LEAK: '.$line);

                    continue 2;
                }
            }
        }

        if (($full['Attachments'] ?? []) !== []) {
            $this->note(sprintf('"%s" carries %d attachment(s)', $message['subject'], count($full['Attachments'])));
        }
    }
}

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('local') || getenv('STH_E2E') !== '1') {
    fwrite(STDERR, "[e2e] refused: only inside the E2E compose project (APP_ENV=local and STH_E2E=1).\n");
    exit(1);
}

$scenario = new EmailScenario(rtrim((string) (getenv('MAILPIT_API') ?: 'http://mailpit:8025'), '/'));

// --- Cast (fictional data only: DemoSeeder + three students imported below) ---------------------------------------

/** key => [NIS, email bound during activation] */
$students = [
    'sekar' => ['226227201', 'sekar.ayu@siswa.sth.test'],       // imported in step 1
    'wulan' => ['226227202', 'wulan.kusuma@siswa.sth.test'],    // imported in step 1
    'bintang' => ['226227203', null],                           // imported, never activated (step 10m)
    'maruf' => ['226227204', 'maruf.hidayat@siswa.sth.test'],   // imported: a name with an apostrophe (step 1e)
    'galuh' => ['224225066', 'galuh.prameswari@siswa.sth.test'],
    'bayu' => ['224225031', 'bayu.anggara@siswa.sth.test'],
    'laras' => ['225226181', 'laras.wening@siswa.sth.test'],
    'galang' => ['226227004', 'galang.putra@siswa.sth.test'],
];

$importCsv = implode("\n", [
    'Nama lengkap,NIS,NISN,Tanggal lahir,Kelas',
    'Sekar Ayu Pramesti,226227201,0109900001,14-02-2011,X-1',
    'Wulan Kusumaningrum,226227202,0109900002,23-08-2010,X-2',
    'Bintang Mahardika,226227203,0109900003,05-11-2010,X-3',
    "Ma'ruf Hidayat,226227204,0109900004,19-06-2010,X-4",
])."\n";

/**
 * A lomba name and a staff note typed as Markdown: both must reach the reader as plain text (SECURITY §3m-1).
 * The name carries no web address: a name with one is refused at input (D-76, checked in step 2g).
 */
$hostileName = '[Masuk di sini](evil-masuk) ![x](evil-gambar) **Lomba Tebal**';
$hostileNote = 'Bukan lomba resmi. Lihat [daftar resmi](https://evil.example/daftar) **segera**.';

$lomba = [
    'l1' => 'Olimpiade Sains Pelajar Nusantara 2026 (E2E)',
    'l2' => 'Lomba Cerdas Cermat Antarkelas Fiktif 2026 (E2E)',
    'l3' => 'Festival Inovasi Pelajar Fiktif 2026 (E2E)',
];

if (Student::withTrashed()->where('nis', $students['sekar'][0])->exists() || Competition::withTrashed()->where('name', $lomba['l1'])->exists()) {
    fwrite(STDERR, "[e2e] this database already holds a scenario run: run tests/E2E/support/setup-app.sh first.\n");
    exit(1);
}

/** Fresh User per call, so no Action sees stale relations of an earlier step. */
$staff = fn (string $username): User => User::query()->where('username', $username)->firstOrFail();
$siswa = fn (string $key): User => Student::query()->where('nis', $students[$key][0])->firstOrFail()->user()->firstOrFail();
$studentId = fn (string $key): string => (string) Student::query()->where('nis', $students[$key][0])->valueOrFail('id');
$at = fn (string $username): string => (string) $staff($username)->email;
$mail = fn (string $key): string => (string) $students[$key][1];

foreach ($students as [, $address]) {
    if ($address !== null) {
        $scenario->knownAs($address, RoleName::Siswa->value);
    }
}

/** @var ArrayObject<string, mixed> $state ids and values passed from step to step */
$state = new ArrayObject;
/** @var ArrayObject<string, string> $passwords current password per student key (never printed) */
$passwords = new ArrayObject;
$today = CarbonImmutable::today();

// --- Fixtures: tiny generated uploads ----------------------------------------------------------------------------

$pdf = function (string $name): UploadedFile {
    $path = (string) tempnam(sys_get_temp_dir(), 'sth-e2e-');
    file_put_contents($path, implode("\n", [
        '%PDF-1.4',
        '% fixture '.bin2hex(random_bytes(6)),
        '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
        '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
        '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >> endobj',
        'trailer << /Size 4 /Root 1 0 R >>',
        '%%EOF',
    ])."\n");

    return new UploadedFile($path, $name, 'application/pdf', null, true);
};

$png = function (string $name, int $width = 240, int $height = 160): UploadedFile {
    $path = (string) tempnam(sys_get_temp_dir(), 'sth-e2e-');
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, random_int(40, 200), random_int(40, 200), random_int(40, 200)));
    imagepng($image, $path);

    return new UploadedFile($path, $name, 'image/png', null, true);
};

// --- Helpers that mirror what the screens do with the Actions ----------------------------------------------------

$newPassword = function (User $user) use ($scenario): string {
    $setter = app(SetNewPassword::class);

    do {
        $password = 'Uji'.bin2hex(random_bytes(5)).'Kt'.random_int(10, 99);
    } while ($setter->policyErrors($user, $password) !== []);

    $scenario->secret($password);

    return $password;
};

/** Activation step 1 (M1-B order, Livewire\Auth\Activation): the student types an email, the OTP goes there. */
$startActivation = function (string $key) use ($siswa, $mail): void {
    if (! app(IssueOtpCode::class)->handle($siswa($key), CompleteActivation::PURPOSE, $mail($key))) {
        throw new RuntimeException('OTP not sent (resend cooldown).');
    }
};

/**
 * Activation steps 2 + 3: the code read from the mail binds the email (CompleteActivation::bindEmail), then the
 * student chooses a new password (changePassword; every account replaces its password, D-138). Step 3 sends no mail.
 */
$finishActivation = function (string $key) use ($siswa, $mail, $scenario, $newPassword, $passwords): bool {
    $code = $scenario->codeFromMail($mail($key), 'Kode verifikasi email');
    $user = $siswa($key);
    $otp = app(VerifyOtpCode::class)->handle($user, CompleteActivation::PURPOSE, $code);

    if ($otp === null) {
        throw new RuntimeException('OTP code rejected.');
    }

    $genuine = hash_equals((string) $otp->code_hash, VerifyOtpCode::hash($user->getKey(), CompleteActivation::PURPOSE, (string) $otp->email, $code));
    app(CompleteActivation::class)->bindEmail($user, $otp);

    $password = $newPassword($user);
    app(CompleteActivation::class)->changePassword($user, $password, $password);
    $passwords[$key] = $password;

    return $genuine;
};

/** One letter's data as Tata Usaha types it (M1-A: the full number is typed and every letter has its own form). */
$draft = fn (string $number, string $place = 'Kota Semarang'): LetterDraft => new LetterDraft(
    number: $number,
    issuedOn: $today,
    basisFrom: 'Panitia OSPN',
    basisNumber: '012/PAN-OSPN/IX/2026',
    basisDate: $today->subDays(10),
    scheduleDay: $today->addDays(14)->locale('id')->translatedFormat('l'),
    scheduleDate: $today->addDays(14)->locale('id')->translatedFormat('j F Y'),
    scheduleTime: LetterDraft::DEFAULT_TIME,
    place: $place,
);

$nomorSiswa = fn (int $n): string => sprintf('400.3.8/%d/MRD/X/%d', $n, $today->year);
$nomorGuru = fn (int $n): string => sprintf('400.3.8.6/%d/%d', $n, $today->year);

/** GET the URL as that user through the real HTTP kernel (route middleware + FileController + FilePolicy). */
$download = function (User $user, string $url): string {
    try {
        Auth::guard('web')->setUser($user);
        $response = app(HttpKernel::class)->handle(Request::create($url, 'GET'));
        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        return sprintf(
            'HTTP %d%s%s',
            $response->getStatusCode(),
            $response->getStatusCode() === 200 ? ' '.$response->headers->get('Content-Type') : '',
            str_starts_with($body, '%PDF-') ? ', a PDF of '.strlen($body).' bytes' : '',
        );
    } catch (Throwable $e) {
        return 'request failed: '.$e::class;
    } finally {
        Auth::guard('web')->forgetUser();
    }
};

$scenario->out('SMAGA Talent Hub: email scenario ('.now()->format('Y-m-d H:i').' '.config('app.timezone').', mail → '.config('mail.mailers.smtp.host').')');
$scenario->out('Line format per email: role, recipient address, subject.');
$scenario->clearMailbox();

// =================================================================================================================
// 0. Setup: a second Humas account, so "every Humas" is more than one mailbox
// =================================================================================================================

$scenario->step('0a', 'Setup: Admin creates a second Humas account, fitri.h (CreateStaffAccount, role given at once)', function () use ($staff, $scenario): void {
    $password = app(GenerateTemporaryPassword::class)->handle().'-'.random_int(1000, 9999);
    $scenario->secret($password);

    app(CreateStaffAccount::class)->handle($staff('operator'), [
        'name' => 'Fitri Handayani',
        'username' => 'fitri.h',
        'email' => 'fitri.h@sth.test',
        'role' => RoleName::Humas->value,
        'password' => $password,
        'honorific' => 'Bu',
        'position' => 'Humas',
    ]);
}, [[$at('operator'), 'Akun staf baru dibuat']]);

// =================================================================================================================
// 1. Import + first-login activation
// =================================================================================================================

$scenario->step('1a', 'Kesiswaan imports four new students from a CSV (ImportStudents: dry run, then commit)', function () use ($scenario, $staff, $importCsv): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'sth-e2e-');
    file_put_contents($path, $importCsv);

    $dryRun = app(ImportStudents::class)->handle($staff('kesiswaan'), $path, 'csv', '2026/2027', true);
    $commit = app(ImportStudents::class)->handle($staff('kesiswaan'), $path, 'csv', '2026/2027', false);
    unlink($path);

    $scenario->note(sprintf('dry run: %d valid / %d invalid · committed: %d accounts (pending_activation)', $dryRun->validCount(), $dryRun->invalidCount(), $commit->created));

    if ($commit->created !== 4) {
        throw new RuntimeException('Import did not create 4 students: '.json_encode(array_column($dryRun->invalidRows(), 'errors')));
    }
});

$scenario->step('1b', 'New student (Sekar) first login: enters her email, the code is mailed (IssueOtpCode)', function () use ($startActivation): void {
    $startActivation('sekar');
}, [[$mail('sekar'), 'Kode verifikasi email']]);

$scenario->step('1c', 'Sekar types the code from the mail, then chooses a new password: activation complete (VerifyOtpCode + CompleteActivation::bindEmail + changePassword)', function () use ($finishActivation, $scenario, $siswa): void {
    $genuine = $finishActivation('sekar');
    $user = $siswa('sekar');
    $scenario->note('code in the mail matches the stored OTP hash: '.($genuine ? 'yes' : 'NO'));
    $scenario->note(sprintf('account: status=%s, email bound=%s, must_change_password=%s', $user->status->value, $user->email !== null ? 'yes' : 'no', $user->must_change_password ? 'yes' : 'no'));

    if (! $genuine) {
        throw new RuntimeException('The code in the OTP mail is not the issued code.');
    }
}, [[$mail('sekar'), 'Email ini terhubung ke SMAGA Talent Hub']]);

$others = ['wulan', 'galuh', 'bayu', 'laras', 'galang'];

$scenario->step('1d', 'Five more students activate the same way (Wulan + demo students Galuh, Bayu, Laras, Galang)', function () use ($others, $startActivation, $finishActivation): void {
    foreach ($others as $key) {
        $startActivation($key);
        $finishActivation($key);
    }
}, array_merge(...array_map(fn (string $key): array => [
    [$mail($key), 'Kode verifikasi email'],
    [$mail($key), 'Email ini terhubung ke SMAGA Talent Hub'],
], $others)));

$scenario->step('1e', 'A student whose name has an apostrophe (Ma\'ruf) activates: the name is printed correctly in both mail parts', function () use ($startActivation, $finishActivation, $scenario, $mail): void {
    $startActivation('maruf');
    $otp = $scenario->newest($mail('maruf'), 'Kode verifikasi email');
    $finishActivation('maruf');
    $notice = $scenario->newest($mail('maruf'), 'Email ini terhubung ke SMAGA Talent Hub');

    $checks = [
        'OTP mail, text part says "Halo Ma\'ruf,"' => str_contains((string) $otp['Text'], "Halo Ma'ruf,"),
        'OTP mail, HTML part keeps the apostrophe escaped (&#039;)' => str_contains((string) $otp['HTML'], 'Halo Ma&#039;ruf,') && ! str_contains((string) $otp['HTML'], "Ma'ruf"),
        'notice mail, text part says "Halo Ma\'ruf,"' => str_contains((string) $notice['Text'], "Halo Ma'ruf,"),
        'notice mail, HTML part reads "Halo Ma\'ruf," (no entity shown as text)' => str_contains(EmailScenario::visible((string) $notice['HTML']), "Halo Ma'ruf,"),
    ];

    foreach ($checks as $label => $passed) {
        $scenario->note($label.': '.($passed ? 'yes' : 'NO'));

        if (! $passed) {
            $scenario->problem('1e', 'apostrophe check failed: '.$label);
        }
    }
}, [
    [$mail('maruf'), 'Kode verifikasi email'],
    [$mail('maruf'), 'Email ini terhubung ke SMAGA Talent Hub'],
]);

// =================================================================================================================
// 2. Lomba uploads + Kesiswaan verification
// =================================================================================================================

$scenario->step('2a', 'Guru (retno.w) uploads a lomba with a brochure (SubmitCompetition)', function () use ($state, $staff, $lomba, $pdf): void {
    $state['l1'] = app(SubmitCompetition::class)->handle($staff('retno.w'), [
        'name' => $lomba['l1'],
        'organizer_name' => 'Himpunan Sains Pelajar Nusantara',
        'website_url' => 'https://example.org/lomba/ospn-2026',
        'description' => 'Usulan dari guru pembina (data fiktif untuk skenario E2E).',
    ], $pdf('brosur-ospn-2026.pdf'))->getKey();
}, [
    [$at('kesiswaan'), 'Verifikasi usulan lomba: '.$lomba['l1']],
    [$at('operator'), 'Verifikasi usulan lomba: '.$lomba['l1']],
]);

$scenario->step('2b', 'Kesiswaan completes the lomba data and publishes it (UpdateCompetition + VerifyCompetition)', function () use ($state, $staff, $lomba, $today): void {
    $competition = Competition::query()->findOrFail($state['l1']);

    app(UpdateCompetition::class)->handle($staff('kesiswaan'), $competition, [
        'name' => $lomba['l1'],
        'description' => 'Olimpiade sains pelajar tingkat nasional (data fiktif untuk skenario E2E).',
        'bidang' => 'sains',
        'organizer_name' => 'Himpunan Sains Pelajar Nusantara',
        'organizer_type' => 'yayasan_lembaga',
        'weight_category' => 'C',
        'event_status' => 'non_pemerintah',
        'level' => 'nasional',
        'mode' => 'luring',
        'registration_opens_at' => $today->subDay()->format('Y-m-d'),
        'registration_closes_at' => $today->addDays(7)->format('Y-m-d'),
        'starts_on' => $today->addDays(14)->format('Y-m-d'),
        'final_on' => $today->addDays(15)->format('Y-m-d'),
        'location' => 'Kota Semarang',
        'participation' => 'keduanya',
        'max_team_size' => 3,
        'default_mentor_id' => $staff('hendra.k')->getKey(),
        'website_url' => 'https://example.org/lomba/ospn-2026',
        'registration_url' => 'https://example.org/daftar/ospn-2026',
    ]);

    app(VerifyCompetition::class)->handle($staff('kesiswaan'), $competition->refresh(), CompetitionStatus::Dipublikasikan);
}, [[$at('retno.w'), 'Lomba dipublikasikan: '.$lomba['l1']]]);

$scenario->step('2c', 'Student (Galuh) proposes a lomba (SubmitCompetition)', function () use ($state, $siswa, $lomba): void {
    $state['l2'] = app(SubmitCompetition::class)->handle($siswa('galuh'), [
        'name' => $lomba['l2'],
        'organizer_name' => 'Panitia Cerdas Cermat Fiktif',
        'website_url' => 'https://example.org/lomba/cerdas-cermat',
    ])->getKey();
}, [
    [$at('kesiswaan'), 'Verifikasi usulan lomba: '.$lomba['l2']],
    [$at('operator'), 'Verifikasi usulan lomba: '.$lomba['l2']],
]);

$scenario->step('2d', 'Kesiswaan rejects the proposal with a note (VerifyCompetition: ditolak)', function () use ($state, $staff): void {
    app(VerifyCompetition::class)->handle(
        $staff('kesiswaan'),
        Competition::query()->findOrFail($state['l2']),
        CompetitionStatus::Ditolak,
        'Lomba antarkelas tidak masuk katalog lomba luar sekolah. Terima kasih atas usulannya.',
    );
}, [[$mail('galuh'), 'Lomba tidak dipublikasikan: '.$lomba['l2']]]);

$scenario->step('2e', 'Admin adds a lomba with the full form (CreateCompetition): lands in the verification queue', function () use ($state, $staff, $lomba, $today, $scenario): void {
    $competition = app(CreateCompetition::class)->handle($staff('operator'), [
        'name' => $lomba['l3'],
        'bidang' => 'riset',
        'organizer_name' => 'Yayasan Inovasi Pelajar Fiktif',
        'organizer_type' => 'yayasan_lembaga',
        'weight_category' => 'D',
        'event_status' => 'non_pemerintah',
        'level' => 'provinsi',
        'mode' => 'daring',
        'registration_opens_at' => $today->format('Y-m-d'),
        'registration_closes_at' => $today->addDays(20)->format('Y-m-d'),
        'starts_on' => $today->addDays(30)->format('Y-m-d'),
        'final_on' => $today->addDays(30)->format('Y-m-d'),
        'location' => 'Daring',
        'participation' => 'individu',
    ]);
    $state['l3'] = $competition->getKey();
    $scenario->note('status after create: '.$competition->status->value.' (the other verifiers are told; the Admin who added it is not)');
}, [[$at('kesiswaan'), 'Verifikasi usulan lomba: '.$lomba['l3']]]);

$scenario->step('2f', 'Kesiswaan publishes the Admin\'s lomba (VerifyCompetition)', function () use ($state, $staff): void {
    app(VerifyCompetition::class)->handle($staff('kesiswaan'), Competition::query()->findOrFail($state['l3']), CompetitionStatus::Dipublikasikan);
}, [[$at('operator'), 'Lomba dipublikasikan: '.$lomba['l3']]]);

/** No link made from the typed text and no image in the HTML part, while the reader still sees the text as typed. */
$assertPlain = function (string $step, array $message, string $typed) use ($scenario): void {
    $html = (string) ($message['HTML'] ?? '');
    $links = preg_match_all('~<a\b[^>]*href="[^"]*evil~i', $html);
    $images = preg_match_all('~<img\b~i', $html);

    $checks = [
        'HTML part has no <a href> made from the typed text ('.$links.' found)' => $links === 0,
        'HTML part has no <img> ('.$images.' found)' => $images === 0,
        'HTML part has no <strong>/<em> made from the typed text' => preg_match('~<(strong|em)>\s*(segera|Lomba Tebal)~i', $html) !== 1,
        'HTML part shows the text as typed' => str_contains(EmailScenario::visible($html), $typed),
        'text part shows the text as typed' => str_contains((string) preg_replace('/\s+/u', ' ', (string) ($message['Text'] ?? '')), $typed),
    ];

    foreach ($checks as $label => $passed) {
        $scenario->note($label.': '.($passed ? 'yes' : 'NO'));

        if (! $passed) {
            $scenario->problem($step, 'Markdown injection check failed: '.$label);
        }
    }
};

$scenario->step('2g', 'Guru proposes a lomba whose NAME is Markdown (a link + an image): mails must show it as plain text; a name with a web address is refused', function () use ($state, $staff, $hostileName, $scenario, $assertPlain, $at): void {
    // D-76: a web address in the name never gets as far as a mail.
    try {
        app(SubmitCompetition::class)->handle($staff('retno.w'), ['name' => 'Lomba Sains, daftar ulang di www.situs-palsu.example']);
        $scenario->problem('2g', 'a lomba name with a web address was accepted');
    } catch (ValidationException $e) {
        $scenario->note('name with a web address refused: "'.(string) ($e->errors()['name'][0] ?? '?').'"');
    }

    $state['l4'] = app(SubmitCompetition::class)->handle($staff('retno.w'), [
        'name' => $hostileName,
        'organizer_name' => 'Panitia Fiktif',
    ])->getKey();

    $message = $scenario->newest($at('kesiswaan'), 'Verifikasi usulan lomba: '.$hostileName);
    $scenario->note('subject shows the literal name: yes (matched below)');
    $assertPlain('2g', $message, $hostileName);
}, [
    [$at('kesiswaan'), 'Verifikasi usulan lomba: '.$hostileName],
    [$at('operator'), 'Verifikasi usulan lomba: '.$hostileName],
]);

$scenario->step('2h', 'Kesiswaan rejects it with a NOTE written as Markdown (VerifyCompetition: ditolak)', function () use ($state, $staff, $hostileName, $hostileNote, $scenario, $assertPlain, $at): void {
    app(VerifyCompetition::class)->handle($staff('kesiswaan'), Competition::query()->findOrFail($state['l4']), CompetitionStatus::Ditolak, $hostileNote);

    $assertPlain('2h', $scenario->newest($at('retno.w'), 'Lomba tidak dipublikasikan: '.$hostileName), $hostileNote);
}, [[$at('retno.w'), 'Lomba tidak dipublikasikan: '.$hostileName]]);

// =================================================================================================================
// 3. Registration: team, invitations, individual, decline, cancel
// =================================================================================================================

$l1 = fn (): Competition => Competition::query()->findOrFail($state['l1']);
$registration = fn (string $key): Registration => Registration::query()->findOrFail($state[$key]);

$scenario->step('3a', 'Galuh registers a TEAM with guru pembimbing hendra.k and invites Bayu + Laras (CreateRegistration)', function () use ($state, $siswa, $staff, $studentId, $l1, $scenario, $mail, $lomba): void {
    $state['r1'] = app(CreateRegistration::class)->handle(
        $siswa('galuh'),
        $l1(),
        RegistrationType::Tim,
        [$studentId('bayu'), $studentId('laras')],
        (int) $staff('hendra.k')->getKey(),
    )->getKey();

    $scenario->assertRendered('3a', $mail('bayu'), 'Undangan tim: '.$lomba['l1'], 'Halo Bayu,', 'Galuh Prameswari mengajak kamu ikut tim '.$lomba['l1'].'.', 'Lihat undangan', '/siswa/undangan/');
}, [
    [$mail('bayu'), 'Undangan tim: '.$lomba['l1']],
    [$mail('laras'), 'Undangan tim: '.$lomba['l1']],
]);

$scenario->step('3b', 'Bayu accepts (ConfirmMembership): team not complete yet', function () use ($siswa, $registration, $scenario): void {
    $result = app(ConfirmMembership::class)->handle($siswa('bayu'), $registration('r1'));
    $scenario->note('registration status: '.$result->status->value);
}, [[$mail('galuh'), 'Anggota ikut tim: '.$lomba['l1']]]);

$scenario->step('3c', 'Laras accepts (ConfirmMembership): team complete, goes to the Kepala Sekolah', function () use ($siswa, $registration, $scenario): void {
    $result = app(ConfirmMembership::class)->handle($siswa('laras'), $registration('r1'));
    $scenario->note('registration status: '.$result->status->value);
}, [
    [$mail('galuh'), 'Anggota ikut tim: '.$lomba['l1']],
    [$at('kepsek'), 'Pendaftaran lomba menunggu persetujuan'],
]);

$scenario->step('3d', 'Galang registers a second TEAM and invites Sekar (CreateRegistration)', function () use ($state, $siswa, $staff, $studentId, $l1): void {
    $state['r2'] = app(CreateRegistration::class)->handle(
        $siswa('galang'),
        $l1(),
        RegistrationType::Tim,
        [$studentId('sekar')],
        (int) $staff('hendra.k')->getKey(),
    )->getKey();
}, [[$mail('sekar'), 'Undangan tim: '.$lomba['l1']]]);

$scenario->step('3e', 'Sekar declines the invitation (DeclineMembership)', function () use ($siswa, $registration, $scenario): void {
    $result = app(DeclineMembership::class)->handle($siswa('sekar'), $registration('r2'));
    $scenario->note('registration status: '.$result->status->value.' (only the ketua is left)');
}, [[$mail('galang'), 'Anggota menolak: '.$lomba['l1']]]);

$scenario->step('3f', 'Galang invites a replacement, Wulan (InviteMembers)', function () use ($siswa, $registration, $studentId): void {
    app(InviteMembers::class)->handle($siswa('galang'), $registration('r2'), [$studentId('wulan')]);
}, [[$mail('wulan'), 'Undangan tim: '.$lomba['l1']]]);

$scenario->step('3g', 'Galang (ketua) cancels the team while it still waits for members (CancelRegistration): member + guru, NOT the Kepala Sekolah', function () use ($siswa, $registration, $scenario, $state, $at, $lomba): void {
    $result = app(CancelRegistration::class)->handle($siswa('galang'), $registration('r2'));
    $scenario->note('registration status: '.$result->status->value.' (Sekar declined earlier, so of the members only Wulan is told; the Kepala Sekolah never heard of this team)');
    $scenario->assertRendered('3g', $at('hendra.k'), 'Pendaftaran binaan dibatalkan', 'Yth. Hendra Kusuma, S.Pd., M.Si.,', 'Galang Putra Mahendra membatalkan pendaftaran binaan di '.$lomba['l1'].'.', 'Lihat pendaftaran', '/guru/binaan/'.$state['r2']);
}, [
    [$mail('wulan'), 'Pendaftaran dibatalkan: '.$lomba['l1']],
    [$at('hendra.k'), 'Pendaftaran binaan dibatalkan'],
]);

$scenario->step('3h', 'Sekar registers INDIVIDUALLY (CreateRegistration): straight to the Kepala Sekolah', function () use ($state, $siswa, $staff, $l1): void {
    $state['r3'] = app(CreateRegistration::class)->handle($siswa('sekar'), $l1(), RegistrationType::Individu, [], (int) $staff('hendra.k')->getKey())->getKey();
}, [[$at('kepsek'), 'Pendaftaran lomba menunggu persetujuan']]);

$scenario->step('3i', 'Wulan registers INDIVIDUALLY (CreateRegistration)', function () use ($state, $siswa, $staff, $l1): void {
    $state['r4'] = app(CreateRegistration::class)->handle($siswa('wulan'), $l1(), RegistrationType::Individu, [], (int) $staff('hendra.k')->getKey())->getKey();
}, [[$at('kepsek'), 'Pendaftaran lomba menunggu persetujuan']]);

$scenario->step('3j', 'Galang registers a new TEAM with Ma\'ruf, who accepts: the team now waits for the Kepala Sekolah (CreateRegistration + ConfirmMembership)', function () use ($state, $siswa, $staff, $studentId, $l1, $scenario): void {
    $created = app(CreateRegistration::class)->handle($siswa('galang'), $l1(), RegistrationType::Tim, [$studentId('maruf')], (int) $staff('hendra.k')->getKey());
    $state['r5'] = $created->getKey();
    $scenario->drain();

    $result = app(ConfirmMembership::class)->handle($siswa('maruf'), $created);
    $scenario->note('registration status: '.$result->status->value);
}, [
    [$mail('maruf'), 'Undangan tim: '.$lomba['l1']],
    [$mail('galang'), 'Anggota ikut tim: '.$lomba['l1']],
    [$at('kepsek'), 'Pendaftaran lomba menunggu persetujuan'],
]);

$scenario->step('3k', 'Galang (ketua) cancels the team that already waits for approval (CancelRegistration): member + guru + Kepala Sekolah', function () use ($siswa, $registration, $scenario, $state, $at, $lomba): void {
    $result = app(CancelRegistration::class)->handle($siswa('galang'), $registration('r5'));
    $scenario->note('registration status: '.$result->status->value.' (it was menunggu_kepsek: the Kepala Sekolah had been asked to decide)');
    $scenario->assertRendered('3k', $at('kepsek'), 'Pendaftaran lomba dibatalkan', 'Yth. Drs. Bambang Hartono, M.Pd.,', 'Galang Putra Mahendra membatalkan pendaftaran '.$lomba['l1'].' (tim). Pendaftaran ini tidak perlu diputuskan lagi.', 'Buka persetujuan', '/kepsek/persetujuan');
    $scenario->assertRendered('3k', $at('hendra.k'), 'Pendaftaran binaan dibatalkan', 'Yth. Hendra Kusuma, S.Pd., M.Si.,', 'Galang Putra Mahendra membatalkan pendaftaran binaan di '.$lomba['l1'].'.', 'Lihat pendaftaran', '/guru/binaan/'.$state['r5']);
}, [
    [$mail('maruf'), 'Pendaftaran dibatalkan: '.$lomba['l1']],
    [$at('hendra.k'), 'Pendaftaran binaan dibatalkan'],
    [$at('kepsek'), 'Pendaftaran lomba dibatalkan'],
]);

// =================================================================================================================
// 4. Kepala Sekolah decides
// =================================================================================================================

$scenario->step('4a', 'Kepala Sekolah approves the team of Galuh (ApproveRegistration)', function () use ($staff, $registration, $scenario, $at): void {
    app(ApproveRegistration::class)->handle($staff('kepsek'), $registration('r1'), 'Semoga sukses.');

    $scenario->assertRendered('4a', $at('tu'), 'Surat tugas perlu dibuat', 'Yth. Sri Handayani,', 'Isi nomor surat lalu buat PDF-nya.', 'Buka antrean surat', '/tu/surat/');
    $scenario->assertRendered('4a', $at('hendra.k'), 'Pendaftaran binaan disetujui', 'Yth. Hendra Kusuma, S.Pd., M.Si.,', 'disetujui Kepala Sekolah.', 'Lihat pendaftaran', '/guru/binaan/'.$registration('r1')->getKey());
}, [
    [$mail('galuh'), 'Pendaftaran lomba disetujui'],
    [$mail('bayu'), 'Pendaftaran lomba disetujui'],
    [$mail('laras'), 'Pendaftaran lomba disetujui'],
    [$at('hendra.k'), 'Pendaftaran binaan disetujui'],
    [$at('tu'), 'Surat tugas perlu dibuat'],
]);

$scenario->step('4b', 'Kepala Sekolah rejects Sekar\'s registration with a note (RejectRegistration): the student and the guru pembimbing', function () use ($staff, $registration, $scenario, $state, $at, $lomba): void {
    $note = 'Jadwal bentrok dengan kegiatan wajib kelas X. Coba lomba berikutnya.';
    app(RejectRegistration::class)->handle($staff('kepsek'), $registration('r3'), $note);

    $scenario->assertRendered('4b', $at('hendra.k'), 'Pendaftaran binaan tidak disetujui', 'Yth. Hendra Kusuma, S.Pd., M.Si.,', 'Pendaftaran binaan di '.$lomba['l1'].' (Sekar Ayu Pramesti) tidak disetujui Kepala Sekolah. Alasan: '.$note, 'Lihat pendaftaran', '/guru/binaan/'.$state['r3']);
}, [
    [$mail('sekar'), 'Pendaftaran lomba tidak disetujui'],
    [$at('hendra.k'), 'Pendaftaran binaan tidak disetujui'],
]);

$scenario->step('4c', 'Kepala Sekolah approves Wulan\'s registration (ApproveRegistration)', function () use ($staff, $registration): void {
    app(ApproveRegistration::class)->handle($staff('kepsek'), $registration('r4'));
}, [
    [$mail('wulan'), 'Pendaftaran lomba disetujui'],
    [$at('hendra.k'), 'Pendaftaran binaan disetujui'],
    [$at('tu'), 'Surat tugas perlu dibuat'],
]);

// =================================================================================================================
// 5. Tata Usaha: letter numbers, issue, send, correct
// =================================================================================================================

$currentLetter = fn (string $key, LetterType $type): Letter => Letter::query()
    ->where('registration_id', $state[$key])->where('type', $type)->whereNull('revoked_at')->firstOrFail();

$scenario->step('5a', 'Tata Usaha types each letter\'s full number and issues the two PDFs for the team, one letter at a time (IssueLetters): not sent yet', function () use ($staff, $registration, $draft, $nomorSiswa, $nomorGuru, $scenario): void {
    $letters = [
        app(IssueLetters::class)->handle($staff('tu'), $registration('r1'), LetterType::Siswa, $draft($nomorSiswa(9001))),
        app(IssueLetters::class)->handle($staff('tu'), $registration('r1'), LetterType::Guru, $draft($nomorGuru(9101))),
    ];

    foreach ($letters as $letter) {
        $file = $letter->file;
        $bytes = (string) $file->storage()->get($file->path);
        $scenario->note(sprintf('%s letter %s → %s, %d bytes, %s', $letter->type->value, $letter->full_number, $file->mime, strlen($bytes), str_starts_with($bytes, '%PDF-') ? 'PDF on the private disk' : 'NOT a PDF'));
    }
});

$scenario->step('5b', 'Tata Usaha sends the letters (SendLetters): siswa letter to the members, Surat Perintah Tugas to the guru', function () use ($staff, $siswa, $registration, $currentLetter, $download, $scenario, $mail): void {
    $result = app(SendLetters::class)->handle($staff('tu'), $registration('r1'));
    $scenario->note('registration status: '.$result->status->value);

    $siswaLetter = $currentLetter('r1', LetterType::Siswa);
    $guruLetter = $currentLetter('r1', LetterType::Guru);
    $siswaUrl = route('files.show', ['file' => $siswaLetter->file_id]);
    $guruUrl = route('files.show', ['file' => $guruLetter->file_id]);
    // QA10: the letter mails link to the screen that lists the current letters (a corrected letter replaces the file).
    $siswaScreen = route('siswa.lomba-saya.show', ['registration' => $siswaLetter->registration_id]);
    $guruScreen = (string) parse_url(route('guru.binaan.show', ['registration' => $guruLetter->registration_id]), PHP_URL_PATH);

    // Workflow sketch: the letters are PDFs in the account of every member and of the guru pendamping.
    $checks = [
        'member Galuh, siswa letter' => [Gate::forUser($siswa('galuh'))->allows('view', $siswaLetter->file), true],
        'member Bayu, siswa letter' => [Gate::forUser($siswa('bayu'))->allows('view', $siswaLetter->file), true],
        'member Laras, siswa letter' => [Gate::forUser($siswa('laras'))->allows('view', $siswaLetter->file), true],
        'guru hendra.k, guru letter' => [Gate::forUser($staff('hendra.k'))->allows('view', $guruLetter->file), true],
        'guru hendra.k, siswa letter' => [Gate::forUser($staff('hendra.k'))->allows('view', $siswaLetter->file), true],
        'outsider Sekar, siswa letter' => [Gate::forUser($siswa('sekar'))->allows('view', $siswaLetter->file), false],
        'other guru retno.w, guru letter' => [Gate::forUser($staff('retno.w'))->allows('view', $guruLetter->file), false],
    ];

    foreach ($checks as $label => [$allowed, $wanted]) {
        $scenario->note(sprintf('FilePolicy view (%s): %s', $label, $allowed ? 'allowed' : 'denied'));

        if ($allowed !== $wanted) {
            $scenario->problem('5b', 'FilePolicy view ('.$label.') should be '.($wanted ? 'allowed' : 'denied'));
        }
    }

    $scenario->note('download as Galuh (siswa letter): '.$download($siswa('galuh'), $siswaUrl));
    $scenario->note('download as hendra.k (guru letter): '.$download($staff('hendra.k'), $guruUrl));
    $scenario->note('download as Sekar, not a member (siswa letter): '.$download($siswa('sekar'), $siswaUrl));

    $text = $scenario->textOf($mail('galuh'), 'Surat tugas kamu sudah terbit');
    $scenario->note('mail to Galuh names number '.$siswaLetter->full_number.': '.(str_contains($text, (string) $siswaLetter->full_number) ? 'yes' : 'NO')
        .' · links to Lomba saya (login required): '.(str_contains($text, $siswaScreen) ? 'yes' : 'NO'));
    $scenario->assertRendered('5b', (string) $staff('hendra.k')->email, 'Surat perintah tugas terbit', 'Yth. Hendra Kusuma, S.Pd., M.Si.,', '(Nomor '.$guruLetter->full_number.') sudah terbit.', 'Lihat surat', $guruScreen);
}, [
    [$mail('galuh'), 'Surat tugas kamu sudah terbit'],
    [$mail('bayu'), 'Surat tugas kamu sudah terbit'],
    [$mail('laras'), 'Surat tugas kamu sudah terbit'],
    [$at('hendra.k'), 'Surat perintah tugas terbit'],
]);

$scenario->step('5c', 'TU issues Wulan\'s letters, spots a typo and corrects the siswa letter BEFORE sending (IssueLetters + RevokeLetter)', function () use ($staff, $registration, $draft, $nomorSiswa, $nomorGuru, $currentLetter, $scenario): void {
    app(IssueLetters::class)->handle($staff('tu'), $registration('r4'), LetterType::Siswa, $draft($nomorSiswa(9002), 'Kota Semarag'));
    app(IssueLetters::class)->handle($staff('tu'), $registration('r4'), LetterType::Guru, $draft($nomorGuru(9102), 'Kota Semarag'));
    $old = $currentLetter('r4', LetterType::Siswa);
    $new = app(RevokeLetter::class)->handle($staff('tu'), $old, 'Salah ketik nama tempat lomba.', $draft($nomorSiswa(9003)));
    $scenario->note(sprintf('revoked %s → new version %d, %s (not sent yet: members are not told)', $old->full_number, $new->version, $new->full_number));
}, [[$at('kepsek'), 'Surat tugas dicabut dan diganti']]);

$scenario->step('5d', 'TU sends Wulan\'s letters (SendLetters)', function () use ($staff, $registration, $currentLetter, $scenario, $mail): void {
    app(SendLetters::class)->handle($staff('tu'), $registration('r4'));
    $letter = $currentLetter('r4', LetterType::Siswa);
    $text = $scenario->textOf($mail('wulan'), 'Surat tugas kamu sudah terbit');
    $scenario->note('mail to Wulan names the corrected number '.$letter->full_number.': '.(str_contains($text, (string) $letter->full_number) ? 'yes' : 'NO'));
}, [
    [$mail('wulan'), 'Surat tugas kamu sudah terbit'],
    [$at('hendra.k'), 'Surat perintah tugas terbit'],
]);

$scenario->step('5e', 'TU corrects the team\'s SISWA letter after it was sent (RevokeLetter)', function () use ($staff, $siswa, $draft, $nomorSiswa, $currentLetter, $scenario): void {
    $old = $currentLetter('r1', LetterType::Siswa);
    $new = app(RevokeLetter::class)->handle($staff('tu'), $old, 'Tanggal pelaksanaan berubah, surat diganti.', $draft($nomorSiswa(9004)));
    $scenario->note(sprintf('revoked %s → version %d, %s', $old->full_number, $new->version, $new->full_number));
    $scenario->note('FilePolicy view for member Galuh: old PDF '.(Gate::forUser($siswa('galuh'))->allows('view', $old->file) ? 'allowed' : 'denied')
        .', new PDF '.(Gate::forUser($siswa('galuh'))->allows('view', $new->file) ? 'allowed' : 'denied'));
}, [
    [$mail('galuh'), 'Surat tugas kamu diperbarui'],
    [$mail('bayu'), 'Surat tugas kamu diperbarui'],
    [$mail('laras'), 'Surat tugas kamu diperbarui'],
    [$at('kepsek'), 'Surat tugas dicabut dan diganti'],
]);

$scenario->step('5f', 'TU corrects the team\'s GURU letter (RevokeLetter)', function () use ($staff, $draft, $nomorGuru, $currentLetter, $scenario): void {
    $old = $currentLetter('r1', LetterType::Guru);
    $new = app(RevokeLetter::class)->handle($staff('tu'), $old, 'Pangkat guru pembimbing salah tulis.', $draft($nomorGuru(9103)));
    $scenario->note(sprintf('revoked %s → version %d, %s', $old->full_number, $new->version, $new->full_number));
}, [
    [$at('hendra.k'), 'Surat perintah tugas diperbarui'],
    [$at('kepsek'), 'Surat tugas dicabut dan diganti'],
]);

// =================================================================================================================
// 6. Timeline: scheduler commands
// =================================================================================================================

$moveLombaDates = function (int $finalDaysAgo) use ($state, $today): void {
    // Arrangement instead of waiting weeks: the lomba took place in the past.
    DB::table('competitions')->where('id', $state['l1'])->update([
        'registration_closes_at' => $today->subDays($finalDaysAgo + 10)->endOfDay(),
        'starts_on' => $today->subDays($finalDaysAgo + 1)->toDateString(),
        'final_on' => $today->subDays($finalDaysAgo)->toDateString(),
    ]);
};

$statuses = fn (): string => 'team: '.Registration::query()->whereKey($state['r1'])->value('status')->value
    .', Wulan: '.Registration::query()->whereKey($state['r4'])->value('status')->value;

$scenario->step('6a', 'Lomba is over (dates moved 31 days back) → `sth:registrations:advance`', function () use ($moveLombaDates, $scenario, $statuses): void {
    $moveLombaDates(31);
    Artisan::call('sth:registrations:advance');
    $scenario->note('command output: '.trim(Artisan::output()));
    $scenario->note('registration status → '.$statuses());
}, [
    [$mail('galuh'), 'Saatnya laporkan hasil '.$lomba['l1']],
    [$mail('wulan'), 'Saatnya laporkan hasil '.$lomba['l1']],
]);

$reminderSays = fn (string $phrase): string => 'reminder to Galuh says "'.$phrase.'": '
    .(str_contains($scenario->textOf($mail('galuh'), 'Laporan '.$lomba['l1'].' ditunggu'), $phrase) ? 'yes' : 'NO');

$scenario->step('6b', '`sth:reports:remind`, 14 days before the deadline (final + 45 days)', function () use ($scenario, $reminderSays): void {
    Artisan::call('sth:reports:remind');
    $scenario->note('command output: '.trim(Artisan::output()));
    $scenario->note($reminderSays('14 hari lagi'));
}, [
    [$mail('galuh'), 'Laporan '.$lomba['l1'].' ditunggu'],
    [$mail('wulan'), 'Laporan '.$lomba['l1'].' ditunggu'],
]);

$scenario->step('6c', '`sth:reports:remind`, 3 days before the deadline (final date moved to 42 days ago)', function () use ($moveLombaDates, $scenario, $reminderSays): void {
    $moveLombaDates(42);
    Artisan::call('sth:reports:remind');
    $scenario->note('command output: '.trim(Artisan::output()));
    $scenario->note($reminderSays('3 hari lagi'));
}, [
    [$mail('galuh'), 'Laporan '.$lomba['l1'].' ditunggu'],
    [$mail('wulan'), 'Laporan '.$lomba['l1'].' ditunggu'],
]);

$scenario->step('6d', '`sth:reports:remind` again the same day: nothing is sent twice', function () use ($scenario): void {
    Artisan::call('sth:reports:remind');
    $scenario->note('command output: '.trim(Artisan::output()));
});

// =================================================================================================================
// 7. Laporan hasil + Kesiswaan verification
// =================================================================================================================

$report = fn (string $key): Report => Report::query()->where('registration_id', $state[$key])->firstOrFail();

$scenario->step('7a', 'Galuh (ketua) submits the laporan hasil with certificate + photo (SubmitReport)', function () use ($siswa, $registration, $pdf, $png): void {
    app(SubmitReport::class)->handle($siswa('galuh'), $registration('r1'), new SubmitReportData(
        result: ResultType::Juara2,
        authentic: true,
        certificate: $pdf('sertifikat-ospn-2026.pdf'),
        photos: [$png('dokumentasi-ospn-1.png')],
    ));
}, [[$at('kesiswaan'), 'Laporan hasil baru: '.$lomba['l1']]]);

$scenario->step('7b', 'Kesiswaan asks for a revision (RequestReportRevision)', function () use ($staff, $report): void {
    app(RequestReportRevision::class)->handle($staff('kesiswaan'), $report('r1'), 'Sertifikat kurang jelas, upload ulang hasil pindai yang terbaca.');
}, [
    // QA10: only the ketua (Galuh) fixes the report; the other members are told it needs a revision.
    [$mail('galuh'), 'Perbaiki laporan hasil: '.$lomba['l1']],
    [$mail('bayu'), 'Laporan hasil perlu revisi: '.$lomba['l1']],
    [$mail('laras'), 'Laporan hasil perlu revisi: '.$lomba['l1']],
    // D-110: the guru pembimbing of r1 hears about every decision on the binaan's report (BinaanReportDecided).
    [$at('hendra.k'), 'Laporan binaan perlu revisi: '.$lomba['l1']],
]);

$scenario->step('7c', 'Galuh sends the corrected report (SubmitReport again)', function () use ($siswa, $registration, $pdf): void {
    app(SubmitReport::class)->handle($siswa('galuh'), $registration('r1'), new SubmitReportData(
        result: ResultType::Juara2,
        authentic: true,
        certificate: $pdf('sertifikat-ospn-2026-pindai-ulang.pdf'),
    ));
}, [[$at('kesiswaan'), 'Laporan hasil diperbaiki: '.$lomba['l1']]]);

$scenario->step('7d', 'Kesiswaan verifies the result (VerifyReport): points for every member, Humas queue', function () use ($state, $staff, $report, $scenario, $mail, $lomba): void {
    $achievements = app(VerifyReport::class)->handle($staff('kesiswaan'), $report('r1'), ResultType::Juara2, array_keys(VerifyReport::CHECKS));
    $state['achievements'] = $achievements->modelKeys();
    $scenario->note(sprintf('%d achievements, %s points each (nasional 80 × juara 2 0,85 × kategori C 0,60)', $achievements->count(), (string) $achievements->first()?->points));
    $scenario->note('mail to Bayu (anggota) says "40,8 poin": '
        .(str_contains($scenario->textOf($mail('bayu'), 'Prestasi terverifikasi: '.$lomba['l1']), '40,8 poin') ? 'yes' : 'NO'));

    // fitri.h was created by the Admin and never verified her address. User::routeNotificationForMail() treats OTP
    // test mode (this environment) as "verified", so she is mailed here; step 11a shows the production rule.
    $fitri = $staff('fitri.h');
    $scenario->note(sprintf('fitri.h: status=%s, email_verified_at=%s, OTP test mode=%s → work mail expected in this environment', $fitri->status->value, $fitri->email_verified_at === null ? 'null' : 'set', VerifyOtpCode::testModeActive() ? 'on' : 'off'));
}, [
    [$mail('galuh'), 'Prestasi terverifikasi: '.$lomba['l1']],
    [$mail('bayu'), 'Prestasi terverifikasi: '.$lomba['l1']],
    [$mail('laras'), 'Prestasi terverifikasi: '.$lomba['l1']],
    [$at('hendra.k'), 'Laporan binaan diverifikasi: '.$lomba['l1']],
    [$at('humas'), 'Prestasi baru siap dipublikasikan'],
    ['fitri.h@sth.test', 'Prestasi baru siap dipublikasikan'],
]);

$scenario->step('7e', 'Wulan submits her laporan hasil (SubmitReport)', function () use ($siswa, $registration, $pdf, $png): void {
    app(SubmitReport::class)->handle($siswa('wulan'), $registration('r4'), new SubmitReportData(
        result: ResultType::Juara1,
        authentic: true,
        certificate: $pdf('sertifikat-ospn-wulan.pdf'),
        photos: [$png('dokumentasi-ospn-wulan.png')],
    ));
}, [[$at('kesiswaan'), 'Laporan hasil baru: '.$lomba['l1']]]);

$scenario->step('7f', 'Kesiswaan rejects Wulan\'s report (RejectReport)', function () use ($staff, $report, $scenario, $state): void {
    app(RejectReport::class)->handle($staff('kesiswaan'), $report('r4'), 'Sertifikat tidak memuat nama peserta dan tidak cocok dengan pengumuman panitia.');
    $scenario->note('registration status: '.Registration::query()->whereKey($state['r4'])->value('status')->value.' (no points)');
}, [[$mail('wulan'), 'Laporan hasil ditolak: '.$lomba['l1']], [$at('hendra.k'), 'Laporan binaan ditolak: '.$lomba['l1']]]);

// =================================================================================================================
// 8. Humas: publication + apresiasi
// =================================================================================================================

$achievementOf = fn (string $key): Achievement => Achievement::query()
    ->whereKey($state['achievements'] ?? [])->where('student_id', $studentId($key))->firstOrFail();
$publicationOf = fn (string $key): Publication => Publication::query()->where('achievement_id', $achievementOf($key)->getKey())->firstOrFail();

$scenario->step('8a', 'Humas publishes Galuh\'s achievement (PublishPublication)', function () use ($staff, $publicationOf): void {
    app(PublishPublication::class)->handle(
        $staff('humas'),
        $publicationOf('galuh'),
        'Selamat kepada tim SMAGA, Juara 2 Olimpiade Sains Pelajar Nusantara 2026!',
        ['instagram', 'website'],
    );
}, [[$mail('galuh'), 'Prestasimu sudah dipublikasikan']]);

$scenario->step('8b', 'Humas schedules an apresiasi for Galuh (CreateAppreciationEvent + RecordAppreciation)', function () use ($staff, $achievementOf, $today): void {
    // D-123: an event has a start time; the student's mail says "… pada <hari, tanggal> pukul 07.00 WIB".
    $event = app(CreateAppreciationEvent::class)->handle($staff('humas'), 'Upacara apresiasi prestasi (E2E)', $today->addDays(10)->format('Y-m-d'), '07:00');
    app(RecordAppreciation::class)->handle($staff('humas'), $event, $achievementOf('galuh'));
    // D-124 / D-110: the guru pembimbing gets one notice for the binaan of this "Masukkan".
}, [[$mail('galuh'), 'Kamu masuk daftar apresiasi'], [$at('hendra.k'), 'Binaan masuk daftar apresiasi']]);

// D-122 (lead, 2026-10-10): the schedule is only a reminder. The job mails Humas and nobody else; the row stays
// terjadwal, and the student is mailed in step 8d, when Humas marks the publication as published.
$scenario->step('8c', 'Humas schedules Bayu\'s publication for "now" (SchedulePublication); the scheduler job RemindDuePublications runs: Humas is reminded, the student is NOT mailed', function () use ($state, $staff, $publicationOf, $scenario): void {
    app(SchedulePublication::class)->handle($staff('humas'), $publicationOf('bayu'), 'Selamat kepada Bayu, Juara 2 Olimpiade Sains Pelajar Nusantara 2026!', ['instagram'], now());

    // Everything whose reminder is due now: Bayu's row (the seeded demo rows with a past time count as reminded).
    $due = RemindDuePublication::due(CarbonImmutable::now())->get();
    $expected = [];

    foreach ($due as $publication) {
        foreach (RemindDuePublication::humasRecipients() as $humas) {
            $expected[] = [(string) $humas->email, 'Waktunya upload publikasi'];
        }
    }

    $state['due'] = $expected;
    $scenario->note(sprintf('%d publication(s) due (Bayu + %d seeded demo row(s))', $due->count(), $due->count() - 1));

    RemindDuePublications::dispatch();
}, fn (): array => (array) $state['due']);

$scenario->step('8d', 'Humas presses "Tandai dipublikasikan" for Bayu (PublishPublication): only now the status changes and the student is mailed', function () use ($staff, $publicationOf, $scenario): void {
    $publication = $publicationOf('bayu');
    $scenario->note(sprintf('after the reminder: status=%s, reminded_at=%s, published_at=%s', $publication->status->value, $publication->reminded_at === null ? 'null' : 'set', $publication->published_at === null ? 'null' : 'set'));

    if ($publication->status !== PublicationStatus::Terjadwal || $publication->reminded_at === null || $publication->published_at !== null) {
        $scenario->problem('8d', 'the reminder job must leave the publication terjadwal (reminded, not published)');
    }

    app(PublishPublication::class)->handle($staff('humas'), $publication);
    $scenario->note('after the button: status='.$publicationOf('bayu')->status->value);
}, [[$mail('bayu'), 'Prestasimu sudah dipublikasikan']]);

// =================================================================================================================
// 9. Talent
// =================================================================================================================

$scenario->step('9a', 'Bayu fills in his talent profile and picks two talents (SaveTalentProfile + ApplyTalent ×2)', function () use ($state, $siswa, $pdf, $scenario): void {
    app(SaveTalentProfile::class)->handle($siswa('bayu'), [
        'interests' => ['Debat', 'Menulis puisi'],
        'skills' => 'Berbicara di depan umum',
        'organizations' => 'Klub debat sekolah (data fiktif)',
        'junior_high_achievements' => 'Juara 2 lomba pidato tingkat kota (data fiktif)',
    ], [$pdf('sertifikat-pidato-smp.pdf')]);

    $pick = function (TalentTypeCode $code, string $branch): array {
        $type = TalentType::query()->get()->firstOrFail(fn (TalentType $t): bool => $t->code === $code);

        return [$type, TalentBranch::query()->where('talent_type_id', $type->getKey())->where('name', $branch)->firstOrFail()];
    };

    [$debat, $debatBranch] = $pick(TalentTypeCode::Debat, 'Debat Bahasa Indonesia (LDBI)');
    [$fls3n, $fls3nBranch] = $pick(TalentTypeCode::Fls3n, 'Baca Puisi');

    $state['talent_a'] = app(ApplyTalent::class)->handle($siswa('bayu'), $debat, $debatBranch, $pdf('bukti-debat.pdf'))->getKey();
    $state['talent_b'] = app(ApplyTalent::class)->handle($siswa('bayu'), $fls3n, $fls3nBranch)->getKey();
    $scenario->note('applied: '.$debatBranch->name.' and FLS3N '.$fls3nBranch->name.' (the Actions send no notification to Kesiswaan)');
});

$scenario->step('9b', 'Kesiswaan moves the first choice to the next stage (MoveTalentStage: terdaftar → pemetaan)', function () use ($state, $staff, $today): void {
    app(MoveTalentStage::class)->handle($staff('kesiswaan'), TalentApplication::query()->findOrFail($state['talent_a']), TalentStatus::Pemetaan, [
        'mentor_id' => $staff('maya.a')->getKey(),
        'assessment_on' => $today->addDays(7)->format('Y-m-d'),
        'note' => null,
    ]);
}, [[$mail('bayu'), 'Tahap talenta berubah: Debat Bahasa Indonesia (LDBI)']]); // the type is not repeated ("Debat Debat …")

$scenario->step('9c', 'Kesiswaan marks the second choice "tidak lolos" (RejectTalentApplication)', function () use ($state, $staff): void {
    app(RejectTalentApplication::class)->handle($staff('kesiswaan'), TalentApplication::query()->findOrFail($state['talent_b']), 'Kuota pembinaan cabang ini sudah penuh. Coba lagi semester depan.');
}, [[$mail('bayu'), 'Hasil seleksi talenta: FLS3N Baca Puisi']]);

// =================================================================================================================
// 10. Account + Admin events
// =================================================================================================================

$scenario->step('10a', 'Laras changes her password in Akun › Keamanan (ChangePassword)', function () use ($siswa, $newPassword, $passwords): void {
    $user = $siswa('laras');
    $new = $newPassword($user);
    app(ChangePassword::class)->handle($user, (string) $passwords['laras'], $new);
    $passwords['laras'] = $new;
}, [[$mail('laras'), 'Kata sandi diganti']]);

$newLarasEmail = 'laras.baru@siswa.sth.test';
$scenario->knownAs($newLarasEmail, RoleName::Siswa->value);

$scenario->step('10b', 'Laras asks to change her email: password + new address (ChangeEmail::requestCode)', function () use ($siswa, $passwords, $newLarasEmail, $scenario): void {
    $user = $siswa('laras');
    $wait = app(IssueOtpCode::class)->secondsUntilResend($user, ChangeEmail::PURPOSE);

    if ($wait > 0) {
        // Same OTP purpose as her activation: the real 60-second resend cooldown applies.
        $scenario->note("waiting {$wait} s for the OTP resend cooldown");
        sleep($wait + 1);
    }

    if (! app(ChangeEmail::class)->requestCode($user, (string) $passwords['laras'], $newLarasEmail)) {
        throw new RuntimeException('OTP not sent (resend cooldown).');
    }
}, [[$newLarasEmail, 'Kode verifikasi email']]);

$scenario->step('10c', 'Laras types the code: email changed (VerifyOtpCode + ChangeEmail::confirm)', function () use ($siswa, $newLarasEmail, $scenario): void {
    $code = $scenario->codeFromMail($newLarasEmail, 'Kode verifikasi email');
    $user = $siswa('laras');
    $otp = app(VerifyOtpCode::class)->handle($user, ChangeEmail::PURPOSE, $code) ?? throw new RuntimeException('OTP code rejected.');
    app(ChangeEmail::class)->confirm($user, $otp);
    $scenario->note('bell notification "Email akun diganti" is database-only by design (no mail)');
}, [
    [$mail('laras'), 'Email akun SMAGA Talent Hub diganti'],
    [$newLarasEmail, 'Email ini sekarang dipakai di SMAGA Talent Hub'],
]);

$scenario->step('10d', 'Bayu forgot his password: asks for a reset code with his NISN (RequestPasswordResetCode)', function () use ($students): void {
    app(RequestPasswordResetCode::class)->handle(Student::query()->where('nis', $students['bayu'][0])->value('nisn'));
}, [[$mail('bayu'), 'Kode atur ulang kata sandi']]);

$scenario->step('10e', 'Bayu types the code + a new password (ResetPasswordWithCode)', function () use ($students, $siswa, $mail, $newPassword, $passwords, $scenario): void {
    $code = $scenario->codeFromMail($mail('bayu'), 'Kode atur ulang kata sandi');
    $new = $newPassword($siswa('bayu'));
    app(ResetPasswordWithCode::class)->handle(Student::query()->where('nis', $students['bayu'][0])->value('nisn'), $code, $new, $new);
    $passwords['bayu'] = $new;
}, [[$mail('bayu'), 'Kata sandi sudah diganti']]);

$temporaryPassword = function () use ($scenario): string {
    $password = app(GenerateTemporaryPassword::class)->handle().'-'.random_int(1000, 9999);
    $scenario->secret($password);

    return $password;
};

$scenario->step('10f', 'Admin creates a staff account with a normal role, guru (CreateStaffAccount)', function () use ($staff, $temporaryPassword): void {
    app(CreateStaffAccount::class)->handle($staff('operator'), [
        'name' => 'Yusuf Hidayat, S.Pd.',
        'username' => 'yusuf.h',
        'email' => 'yusuf.h@sth.test',
        'role' => RoleName::Guru->value,
        'password' => $temporaryPassword(),
        'honorific' => 'Pak',
        'position' => 'Guru pembina Sains',
    ]);
}, [[$at('operator'), 'Akun staf baru dibuat']]);

$scenario->knownAs('rina.k@sth.test', 'staf_baru'); // no role until the request applies

$scenario->step('10g', 'Admin creates a staff account with a privileged role, Tata Usaha (CreateStaffAccount → role request, 24 h delay: only one Admin)', function () use ($staff, $temporaryPassword): void {
    app(CreateStaffAccount::class)->handle($staff('operator'), [
        'name' => 'Rina Kartikasari',
        'username' => 'rina.k',
        'email' => 'rina.k@sth.test',
        'role' => RoleName::TataUsaha->value,
        'password' => $temporaryPassword(),
        'honorific' => 'Bu',
        'position' => 'Staf Tata Usaha',
    ]);
}, [
    [$at('operator'), 'Akun staf baru menunggu persetujuan peran'],
    ['rina.k@sth.test', 'Akun staf baru menunggu persetujuan peran'],
    [$at('kepsek'), 'Akun staf baru menunggu persetujuan peran'],
]);

$scenario->step('10h', 'Admin resets a staff account, guru agus.s (ResetStaffAccount)', function () use ($staff, $temporaryPassword, $scenario, $at): void {
    app(ResetStaffAccount::class)->handle($staff('operator'), $staff('agus.s'), $temporaryPassword(), 'Lupa kata sandi dan ponsel 2FA hilang (skenario E2E).');

    $text = $scenario->textOf($at('operator'), 'Akun staf direset');
    $single = str_contains($text, 'akun Agus Setiawan, S.Or.') && ! str_contains($text, 'S.Or..');
    $scenario->note('sentence ends "…akun Agus Setiawan, S.Or." with one full stop: '.($single ? 'yes' : 'NO'));

    if (! $single) {
        $scenario->problem('10h', 'the reset mail ends the name with a double full stop');
    }
}, [
    [$at('operator'), 'Akun staf direset'],
    [$at('agus.s'), 'Akun staf direset'],
]);

$scenario->step('10i', 'Admin requests a role change, guru maya.a → Kesiswaan (RequestRoleChange, 24 h delay: only one Admin)', function () use ($staff): void {
    app(RequestRoleChange::class)->handle($staff('operator'), $staff('maya.a'), RoleName::Kesiswaan, 'Membantu tugas Kesiswaan (skenario E2E).');
}, [
    [$at('operator'), 'Perubahan peran menunggu persetujuan'],
    [$at('maya.a'), 'Perubahan peran menunggu persetujuan'],
    [$at('kepsek'), 'Perubahan peran menunggu persetujuan'],
]);

$scenario->step('10j', 'Admin publishes a new weight version (PublishWeightVersion)', function () use ($staff, $scenario, $at): void {
    $table = WeightVersion::active()?->toWeightTable() ?? throw new RuntimeException('No active weight version.');
    $weights = ['level' => $table->levels(), 'result' => $table->results(), 'organizer' => $table->organizers()];
    $weights['result']['finalis'] = '0.55';

    app(PublishWeightVersion::class)->handle($staff('operator'), $weights, 'Uji skenario E2E: finalis 0,55.');

    $scenario->assertRendered('10j', $at('kepsek'), 'Bobot poin diubah', 'Yth. Drs. Bambang Hartono, M.Pd.,', 'Operator STH menyimpan bobot poin baru, berlaku');
}, [
    [$at('kepsek'), 'Bobot poin diubah'],
    [$at('operator'), 'Bobot poin diubah'],
]);

$scenario->step('10k', 'Admin replaces the signature + stamp image (ReplaceSignatureImage)', function () use ($staff, $png): void {
    app(ReplaceSignatureImage::class)->handle($staff('operator'), $png('ttd-stempel-fiktif.png', 400, 160));
}, [
    [$at('kepsek'), 'Tanda tangan surat diganti'],
    [$at('operator'), 'Tanda tangan surat diganti'],
]);

$scenario->step('10l', 'Tata Usaha changes the letter template (UpdateLetterTemplate)', function () use ($staff): void {
    app(UpdateLetterTemplate::class)->handle($staff('tu'), [
        ...LetterTemplate::current()->values,
        'kop_5' => 'Laman prestasi.contoh.sch.id (data uji E2E)',
    ]);
}, [
    [$at('kepsek'), 'Template surat tugas diubah'],
    [$at('operator'), 'Template surat tugas diubah'],
]);

$scenario->step('10m', '10 wrong passwords on a not-yet-activated account, Bintang (RecordFailedLogin ×10): frozen', function () use ($siswa, $scenario): void {
    $frozen = false;

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $frozen = app(RecordFailedLogin::class)->handle($siswa('bintang'));
    }

    $scenario->note('frozen after the 10th failure: '.($frozen ? 'yes' : 'NO').', frozen_reason='.(string) $siswa('bintang')->frozen_reason
        .' (this is the Action the Fortify `Failed` listener calls; the HTTP login form is not driven here)');
}, [
    [$at('operator'), 'Akun dibekukan setelah 10 kali gagal masuk'],
    [$at('kesiswaan'), 'Akun dibekukan setelah 10 kali gagal masuk'],
]);

$scenario->step('10n', '"Bukan saya?": the link in the notice to Laras\'s OLD address is used (FreezeAccountFromNotice)', function () use ($siswa, $mail, $scenario): void {
    $text = $scenario->textOf($mail('laras'), 'Email akun SMAGA Talent Hub diganti');

    if (preg_match('~https?://\S+/akun/bukan-saya/([0-9A-Za-z]{26})\?\S*signature=[0-9a-f]+~', $text, $match) !== 1) {
        throw new RuntimeException('The notice mail carries no "Bukan saya?" link.');
    }

    $scenario->note('signed link in the mail is valid: '.(URL::hasValidSignature(Request::create($match[0])) ? 'yes' : 'NO'));

    $frozen = app(FreezeAccountFromNotice::class)->handle($match[1]);
    $scenario->note('account frozen: '.($frozen !== null ? 'yes' : 'NO').', frozen_reason='.(string) $siswa('laras')->frozen_reason
        .' · the link works a second time: '.(FreezeAccountFromNotice::usable($match[1]) ? 'YES' : 'no'));
}, [[$at('operator'), 'Laporan "Bukan saya": akun dibekukan']]);

// Laras is frozen now and her owner disputed the new address: that address must not get any mail (D-68).

$scenario->step('10o', 'Frozen account: Humas publishes Laras\'s achievement (PublishPublication): NO mail to the disputed address', function () use ($staff, $siswa, $publicationOf, $scenario, $newLarasEmail): void {
    $user = $siswa('laras');
    $scenario->note(sprintf('Laras: status=%s, frozen_reason=%s, bound email = the disputed new address: %s', $user->status->value, (string) $user->frozen_reason, $user->email === $newLarasEmail ? 'yes' : 'no'));

    $before = $user->notifications()->count();
    app(PublishPublication::class)->handle($staff('humas'), $publicationOf('laras'), 'Selamat kepada tim SMAGA, Juara 2 Olimpiade Sains Pelajar Nusantara 2026!', ['website']);
    $scenario->drain();
    $scenario->note('in-app (bell) notifications stored for her in this step: '.($siswa('laras')->notifications()->count() - $before).' (the mail channel is skipped, the bell is kept)');
}); // nothing expected: any mail that arrives is UNEXPECTED and fails the run

$scenario->step('10p', 'Frozen account: "Lupa kata sandi" (RequestPasswordResetCode + ResetPasswordWithCode): NO code, reset refused', function () use ($students, $siswa, $newPassword, $scenario): void {
    $user = $siswa('laras');
    $hash = (string) $user->password;
    $codes = fn (): int => $user->otpCodes()->where('purpose', RequestPasswordResetCode::PURPOSE)->count();
    $before = $codes();

    app(RequestPasswordResetCode::class)->handle(Student::query()->where('nis', $students['laras'][0])->value('nisn'));
    $scenario->note('reset codes issued for her: '.($codes() - $before));

    if ($codes() !== $before) {
        $scenario->problem('10p', 'a reset code was issued for a frozen account');
    }

    $new = $newPassword($user);

    try {
        app(ResetPasswordWithCode::class)->handle(Student::query()->where('nis', $students['laras'][0])->value('nisn'), '123456', $new, $new);
        $scenario->problem('10p', 'ResetPasswordWithCode succeeded for a frozen account');
    } catch (ValidationException $e) {
        $scenario->note('ResetPasswordWithCode refused: '.json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
    }

    if (! hash_equals($hash, (string) $siswa('laras')->password)) {
        $scenario->problem('10p', 'the password of the frozen account changed');
    }
}); // nothing expected

// =================================================================================================================
// 11. The production mail rule (User::routeNotificationForMail, D-68). OTP test mode counts as "verified", so it is
//     switched off inside this process for these steps only (queue drained before it is switched back on).
// =================================================================================================================

$production = function (Closure $work) use ($scenario): void {
    $original = config('fortify.sth.otp_test_mode');
    config(['fortify.sth.otp_test_mode' => false]);

    try {
        $work();
        $scenario->drain();
    } finally {
        config(['fortify.sth.otp_test_mode' => $original]);
    }
};

$scenario->step('11a', 'Production rule: a scheduled publication becomes due → work mail only to the VERIFIED Humas address', function () use ($staff, $scenario, $production): void {
    $humas = $staff('humas');
    $fitri = $staff('fitri.h');
    $scenario->note(sprintf('humas: email_verified_at=%s · fitri.h: status=%s, email_verified_at=%s', $humas->email_verified_at === null ? 'null' : 'set', $fitri->status->value, $fitri->email_verified_at === null ? 'null' : 'set'));

    if ($humas->email_verified_at === null || $fitri->email_verified_at !== null) {
        throw new RuntimeException('Precondition failed: humas must be verified and fitri.h unverified.');
    }

    // A demo achievement that is still "siap" and whose student has no email yet. The reminder job mails Humas only
    // (D-122: never the student), so the one expected mail below is the whole result.
    $publication = Publication::query()
        ->where('status', PublicationStatus::Siap)
        ->whereHas('achievement', fn ($a) => $a->whereNotNull('verified_at')
            ->whereHas('student', fn ($s) => $s->where('publish_consent', true)->whereHas('user', fn ($u) => $u->whereNull('email'))))
        ->orderBy('id')
        ->firstOrFail();

    $production(function () use ($humas, $publication, $scenario): void {
        $scenario->note('OTP test mode inside this step: '.(VerifyOtpCode::testModeActive() ? 'ON' : 'off'));
        app(SchedulePublication::class)->handle($humas, $publication, 'Selamat atas prestasinya! (uji aturan produksi)', ['website'], now());

        // Bayu's row was reminded in 8c and the seeded demo rows count as reminded, so only this one is due.
        $due = RemindDuePublication::due(CarbonImmutable::now())->count();
        $scenario->note($due.' publication due; Humas accounts that can log in: '.RemindDuePublication::humasRecipients()->count());

        if ($due !== 1) {
            throw new RuntimeException('Expected exactly one due publication.');
        }

        RemindDuePublications::dispatch();
    });

    $scenario->note('bell notifications "publication.due" for fitri.h (stored even without mail): '
        .$fitri->notifications()->where('type', PublicationDue::class)->count());
}, [[$at('humas'), 'Waktunya upload publikasi']]); // fitri.h (unverified, pending) must NOT be here

$scenario->step('11b', 'Production rule: the same unverified account still gets its own account notice (AdminNotice): Admin resets fitri.h', function () use ($staff, $temporaryPassword, $production): void {
    $production(function () use ($staff, $temporaryPassword): void {
        app(ResetStaffAccount::class)->handle($staff('operator'), $staff('fitri.h'), $temporaryPassword(), 'Salah ketik email saat akun dibuat (skenario E2E).');
    });
}, [
    [$at('operator'), 'Akun staf direset'],
    ['fitri.h@sth.test', 'Akun staf direset'],
]);

exit($scenario->summary());
