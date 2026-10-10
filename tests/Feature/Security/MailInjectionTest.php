<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Mail\OtpCodeMail;
use App\Models\Registration;
use App\Models\Report;
use App\Notifications\Accounts\AccountAlert;
use App\Notifications\Accounts\EmailBindingNotice;
use App\Notifications\Admin\LetterRevokedNotice;
use App\Notifications\Admin\RoleChangeNotice;
use App\Notifications\Admin\SignatureReplaced;
use App\Notifications\Approval\RegistrationRejected;
use App\Notifications\Auth\PasswordWasReset;
use App\Notifications\Competitions\CompetitionSubmitted;
use App\Notifications\Registrations\TeamInvitation;
use App\Notifications\Support\PlainMailMessage;
use App\Notifications\Verification\ReportRevisionRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\HtmlString;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Mime\Email;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| Notification mails are Markdown (MailMessage) and carry text typed by users: lomba names, people's names, staff
| notes. That text must stay text: no link, no remote image, no formatting (SECURITY §3m-1). Every mail is really
| sent through the array mailer and both parts (HTML + text) are inspected.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    config(['mail.default' => 'array']);
});

function mailInjectionLastMail(): Email
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

function mailInjectionSend(object $notifiable, object $notification): Email
{
    Notification::sendNow($notifiable, $notification, ['mail']);

    return mailInjectionLastMail();
}

/**
 * Every address the HTML part points at (links, images, forms, frames).
 *
 * @return list<string>
 */
function mailInjectionTargets(string $html): array
{
    preg_match_all('~<[a-z0-9]+\b[^>]*?\s(?:href|src|action|background)\s*=\s*(["\'])(.*?)\1~is', $html, $matches);

    return array_values(array_unique(array_map(fn (string $url): string => html_entity_decode($url, ENT_QUOTES | ENT_HTML5), $matches[2])));
}

/** The text a reader sees in the HTML part. */
function mailInjectionVisible(string $html): string
{
    $body = (string) preg_replace('~<(style|head)\b.*?</\1>~is', '', $html);

    return trim((string) preg_replace('~\s+~', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)));
}

/**
 * A notification that prints the hostile text, and the account it is mailed to.
 *
 * @return array{0: object, 1: object}
 */
function mailInjectionCase(string $kind, string $hostile): array
{
    $student = F::student();

    return match ($kind) {
        'usulan lomba' => [
            F::staff(RoleName::Kesiswaan),
            new CompetitionSubmitted(F::pendingCompetition(F::user($student), ['name' => $hostile]), $hostile),
        ],
        'undangan tim' => [
            F::user($student),
            new TeamInvitation(Registration::factory()->team()->create([
                'competition_id' => F::teamCompetition(3, ['name' => $hostile])->getKey(),
                'leader_student_id' => F::student($hostile)->getKey(),
            ])),
        ],
        'pendaftaran ditolak' => [
            F::user($student),
            new RegistrationRejected(tap(Registration::factory()->create([
                'competition_id' => F::openCompetition(['name' => $hostile])->getKey(),
                'leader_student_id' => $student->getKey(),
            ]), fn (Registration $registration) => $registration->forceFill(['principal_note' => $hostile])->save())),
        ],
        'revisi laporan' => [F::user($student), ReportRevisionRequested::for(Report::factory()->create(), $hostile, $hostile)],
        'surat dicabut' => [
            F::staff(RoleName::KepalaSekolah),
            new LetterRevokedNotice($hostile, 'Surat Tugas', '400.3.8/313/MRD/X/2026', '400.3.8/314/MRD/X/2026', $hostile, $hostile),
        ],
        'peringatan akun' => [
            F::staff(RoleName::Admin),
            new AccountAlert('account.frozen', 'Akun dibekukan', [$hostile.' (XI-4) dibekukan setelah 10 kali gagal masuk.']),
        ],
    };
}

dataset('hostile text', [
    'link and image' => ['[Masuk ulang di sini](https://evil.example/login) ![x](https://evil.example/p.png)'],
    'backslash before the bracket' => ['\[Masuk](https://evil.example/login) !\[x](https://evil.example/p.png)'],
    'two backslashes' => ['\\\\[Masuk](https://evil.example/login)'],
    'autolink and raw HTML' => ['<https://evil.example/a> <a href="https://evil.example/b">b</a> <img src="https://evil.example/p.png">'],
    'reference link' => ['[Masuk][m] [m]: https://evil.example/login'],
    'character references' => ['&#91;Masuk&#93;(https://evil.example/login) &lt;a href=https://evil.example/c&gt;c&lt;/a&gt;'],
    'formatting' => ['# Judul **tebal** _miring_ `kode` 1. satu - dua | a | b |'],
]);

it('keeps user text as plain text in the mail', function (string $hostile, string $kind): void {
    [$notifiable, $notification] = mailInjectionCase($kind, $hostile);

    $mail = mailInjectionSend($notifiable, $notification);
    $html = (string) $mail->getHtmlBody();

    foreach (mailInjectionTargets($html) as $target) {
        expect($target)->toStartWith((string) config('app.url'));
    }

    // Laravel removes the backslash of "\[" from the finished HTML part; the text part keeps it.
    expect($html)->not->toContain('evil.example"')
        ->and(preg_match('~<(img|strong|em|code|ol|ul|li|th|del|blockquote|hr|script|iframe|form)\b~i', $html))->toBe(0)
        ->and(substr_count($html, '<h1'))->toBeLessThanOrEqual(1)
        ->and(mailInjectionVisible($html))->toContain(str_replace('\[', '[', $hostile))
        ->and((string) $mail->getTextBody())->toContain($hostile);
})->with('hostile text')->with(['usulan lomba', 'undangan tim', 'pendaftaran ditolak', 'revisi laporan', 'surat dicabut', 'peringatan akun']);

// D-129: the published template (resources/views/vendor). The verifier is staff, so the greeting is formal; the
// address under the button is text, never a second link; the text part carries no Markdown ("# Halo," before).
it('still renders the greeting, the lines, the action button and the fallback address', function (): void {
    $name = 'Olimpiade Sains Nasional (OSN) 2026 - "Tingkat Kota" & Provinsi, 40,8 poin *)';
    $competition = F::pendingCompetition(F::user(F::student()), ['name' => $name]);
    $url = route('kesiswaan.lomba.index', ['tab' => 'verifikasi']);

    $mail = mailInjectionSend(F::staff(RoleName::Kesiswaan), new CompetitionSubmitted($competition, 'Nadia Putri Anindya'));
    $html = (string) $mail->getHtmlBody();
    $text = (string) $mail->getTextBody();

    expect($mail->getSubject())->toBe('Usulan lomba baru: '.$name)
        ->and($html)->toMatch('~<h1[^>]*>Yth\. Hendra Kusuma, S\.Pd\., M\.Si\.,</h1>~')
        ->and($html)->toMatch('~<a href="'.preg_quote(e($url), '~').'" class="button button-primary"[^>]*>Buka antrean</a>~')
        ->and($html)->toMatch('~<span class="break-all"[^>]*>'.preg_quote(e($url), '~').'</span>~')
        ->and(preg_match_all('~<a\b~i', $html))->toBe(1)
        ->and(mailInjectionTargets($html))->toBe([$url])
        ->and(substr_count($html, '<p'))->toBeGreaterThanOrEqual(4)
        ->and(mailInjectionVisible($html))->toContain('Nadia Putri Anindya mengusulkan '.$name.'. Periksa penyelenggara dan link-nya sebelum dipublikasikan.')
        ->and(mailInjectionVisible($html))->toContain('Tombol "Buka antrean" tidak bisa dibuka? Salin alamat ini ke browser:'.$url)
        ->and(mailInjectionVisible($html))->not->toContain('Jika tombol')
        ->and(explode("\n", $text)[0])->toBe('Yth. Hendra Kusuma, S.Pd., M.Si.,')
        ->and(preg_match('/^\s*#/m', $text))->toBe(0)
        ->and($text)->not->toContain('](')
        ->and($text)->toContain('Nadia Putri Anindya mengusulkan '.$name.'.')
        ->and($text)->toContain('Buka antrean: '.$url);
});

it('keeps a signed action URL and punctuation in the texts intact', function (): void {
    $url = 'http://127.0.0.1:8080/akun/bukan-saya/01jabc?expires=1790000000&signature=0123abcd';
    $mail = mailInjectionSend(F::user(F::student()), new EmailBindingNotice(EmailBindingNotice::CHANGED_OLD, "Ma'ruf", 'n•••@gmail.com', $url, 7));

    expect(mailInjectionTargets((string) $mail->getHtmlBody()))->toContain($url)
        ->and((string) $mail->getHtmlBody())->toContain('href="http://127.0.0.1:8080/akun/bukan-saya/01jabc?expires=1790000000&amp;signature=0123abcd" class="button')
        ->and((string) $mail->getTextBody())->toContain('Bukan saya, bekukan akun: '.$url);

    $lines = [
        [new EmailBindingNotice(EmailBindingNotice::CHANGED_OLD, "Ma'ruf", 'n•••@gmail.com', $url, 7), "Halo Ma'ruf,", 'baru saja diganti ke n•••@gmail.com.'],
        [new PasswordWasReset, 'Halo Nadia,', 'baru saja diganti lewat "Lupa kata sandi". Semua perangkat sudah dikeluarkan.'],
        [new LetterRevokedNotice('Ibu Siti', 'Surat Tugas', '400.3.8/313/MRD/X/2026', '400.3.8.6/14/2026', 'OSN', 'Salah tulis'), 'Penggantinya: nomor 400.3.8.6/14/2026.', 'Ibu Siti mencabut Surat Tugas nomor 400.3.8/313/MRD/X/2026 (OSN).'],
        [new RoleChangeNotice(RoleChangeNotice::REQUESTED, 'Raka', 'Sari', 'Tata Usaha', '3 Okt 2026, 09.00'), 'Raka meminta peran Sari diubah menjadi Tata Usaha.', 'Jika keliru, minta Admin membatalkannya.'], // QA10: the reader here is no Admin
        [new SignatureReplaced('Raka'), 'Raka meng-upload gambar tanda tangan + stempel baru.', 'Jika tanpa persetujuan Kepala Sekolah, hubungi Admin sekarang.'],
    ];

    foreach ($lines as [$notification, $first, $second]) {
        $mail = mailInjectionSend(F::user(F::student()), $notification);

        expect(mailInjectionVisible((string) $mail->getHtmlBody()))->toContain($first)->toContain($second)
            ->and((string) $mail->getTextBody())->toContain($first)->toContain($second);
    }
});

it('lets trusted markup through only as an HtmlString', function (): void {
    $html = (string) (new PlainMailMessage)
        ->line('Teks **biasa**.')
        ->line(new HtmlString('Lihat **panduan** di [bantuan]('.url('/bantuan').').'))
        ->render();

    expect($html)->toMatch('~<strong[^>]*>panduan</strong>~')
        ->and($html)->toContain('href="'.url('/bantuan').'"')
        ->and($html)->toContain('Teks **biasa**.');
});

it('keeps the Markdown secured encoding switched on for any other mail', function (): void {
    $html = (string) (new MailMessage)->line('[Masuk](https://evil.example/login) <b>tebal</b> ![x](https://evil.example/p.png)')->render();

    expect($html)->not->toContain('evil.example"')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('<b>');
});

it('builds every notification mail with PlainMailMessage', function (): void {
    $offenders = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        if (preg_match('~new\s+\\\\?(Illuminate\\\\Notifications\\\\Messages\\\\)?MailMessage\b|->markdown\(|extends\s+MailMessage\b~', $file->getContents()) === 1
            && $file->getFilename() !== 'PlainMailMessage.php') {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('cannot inject mail headers through a subject built from user text', function (): void {
    $name = "Lomba\r\nBcc: korban@evil.example\r\nX-Injected: ya\n\nIsi palsu";
    $verifier = F::staff(RoleName::Kesiswaan);
    $competition = F::pendingCompetition(F::user(F::student()), ['name' => $name]);

    $mail = mailInjectionSend($verifier, new CompetitionSubmitted($competition, 'Nadia Putri Anindya'));
    $headers = strstr($mail->toString(), "\r\n\r\n", true);

    expect($mail->getBcc())->toBe([])
        ->and($mail->getHeaders()->has('X-Injected'))->toBeFalse()
        ->and(array_map(fn ($address) => $address->getAddress(), $mail->getTo()))->toBe([$verifier->email])
        ->and(preg_match('~^(Bcc|X-Injected):~mi', (string) $headers))->toBe(0)
        ->and((string) $headers)->not->toContain('korban@evil.example')
        ->and((string) $mail->getHtmlBody())->not->toContain('<a href="mailto:');
});

it('renders the OTP mail as plain Blade: escaped, no Markdown', function (): void {
    Mail::to('nadia@contoh.test')->sendNow(new OtpCodeMail('**Nadia**[x](https://evil.example/a)<b>', '482913', OtpCodeMail::PURPOSE_VERIFY_EMAIL, 10));

    $mail = mailInjectionLastMail();
    $html = (string) $mail->getHtmlBody();

    expect($mail->getSubject())->toBe('Kode verifikasi email')
        ->and($html)->toContain('Halo **Nadia**[x](https://evil.example/a)&lt;b&gt;,')
        ->and($html)->toContain('>482913</p>')
        ->and($html)->toContain('Kode berlaku 10 menit')
        ->and(mailInjectionTargets($html))->toBe([])
        ->and($html)->not->toContain('<strong')
        ->and((string) $mail->getTextBody())->toContain('482913')->toContain('Kode berlaku 10 menit');
});
