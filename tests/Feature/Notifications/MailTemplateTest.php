<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Mail\OtpCodeMail;
use App\Models\Registration;
use App\Notifications\Accounts\EmailBindingNotice;
use App\Notifications\Auth\PasswordWasReset;
use App\Notifications\Registrations\InvitationsExpired;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| The lead's mail test of 2026-10-09 (step 11-2, "pastikan tidak ada yang aneh"): the text part of a notification
| mail carried Markdown ("# Halo Nadia,", "[http://…](http://…)"), opened with "SMAGA Talent Hub: <address>", ended
| with a copyright line, and the HTML part was Laravel's default look with three links (header, button, fallback).
| D-129: the published templates (resources/views/vendor/{notifications,mail}), the theme sth.css, one footer
| sentence, the action button as the only link. Every mail is really sent through the array mailer and both parts
| are read. Greeting rule: MailGreetingTest. User text stays text: also MailInjectionTest. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    config(['mail.default' => 'array']);
});

function mailTemplateLastMail(): Email
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

function mailTemplateSend(object $notifiable, object $notification): Email
{
    Notification::sendNow($notifiable, $notification, ['mail']);

    return mailTemplateLastMail();
}

/**
 * Every address the HTML part points at (links, images, forms, frames).
 *
 * @return list<string>
 */
function mailTemplateTargets(string $html): array
{
    preg_match_all('~<[a-z0-9]+\b[^>]*?\s(?:href|src|action|background)\s*=\s*(["\'])(.*?)\1~is', $html, $matches);

    return array_values(array_unique(array_map(fn (string $url): string => html_entity_decode($url, ENT_QUOTES | ENT_HTML5), $matches[2])));
}

/** The text a reader sees in the HTML part (the hidden inbox preview line is not part of it). */
function mailTemplateVisible(string $html): string
{
    $body = (string) preg_replace(['~<(style|head)\b.*?</\1>~is', '~<div class="preheader".*?</div>~is'], '', $html);

    return trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)));
}

/** Smallest font size an HTML part sets, in px. */
function mailTemplateSmallestFont(string $html): float
{
    preg_match_all('~font-size:\s*([0-9.]+)px~i', $html, $sizes);

    expect($sizes[1])->not->toBeEmpty();

    return (float) min(array_map(floatval(...), $sizes[1]));
}

/** A student (Nadia), her team registration and the page the mail's button opens. */
function mailTemplateRegistration(): array
{
    $student = F::student();
    $registration = Registration::factory()->team()->create([
        'competition_id' => F::teamCompetition(3, ['name' => 'Lomba Karya Tulis Ilmiah Nasional 2026'])->getKey(),
        'leader_student_id' => $student->getKey(),
    ]);

    return [F::user($student), $registration, route('siswa.lomba-saya.show', ['registration' => $registration->getKey()])];
}

/**
 * The serialized object as a job that was queued before the property existed carries it: without that property.
 * (The framework already leaves out a property that still has its default value; then nothing is removed.)
 */
function mailTemplatePayloadWithout(object $object, string $property): string
{
    $payload = serialize($object);
    $pair = 's:'.strlen($property).':"'.$property.'";N;';

    if (! str_contains($payload, $pair)) {
        return $payload;
    }

    $payload = (string) preg_replace('/'.preg_quote($pair, '/').'/', '', $payload, 1);

    return (string) preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', fn (array $m): string => 'O:'.$m[1].':"'.$m[2].'":'.((int) $m[3] - 1).':', $payload);
}

/**
 * The compiled Blade views of this test only, so the test decides which echo format compiles the mail views
 * (the files are keyed by path: whichever part is rendered first compiles the shared notification view).
 */
function mailTemplateFreshCompiledViews(): void
{
    $path = config('view.compiled').'/mail-order-'.bin2hex(random_bytes(6));
    (new Filesystem)->ensureDirectoryExists($path);

    (function () use ($path): void {
        $this->cachePath = $path;
    })->call(app('blade.compiler'));
}

/**
 * Every Blade file a notification mail is made of: the notification view and the html + text mail components
 * (published ones and the framework's), with the path exactly as the view finder builds it.
 *
 * @return list<string>
 */
function mailTemplateViewFiles(): array
{
    $markdown = app(Markdown::class);
    $files = [view()->getFinder()->find('notifications::email')];

    foreach ([...$markdown->htmlComponentPaths(), ...$markdown->textComponentPaths()] as $directory) {
        foreach ((array) glob($directory.'/*.blade.php') as $file) {
            $files[] = (string) $file;
        }
    }

    return $files;
}

// The text part ------------------------------------------------------------------------------------------------------

it('sends a text part without Markdown leftovers: greeting, lines, "Label: address", footer', function (): void {
    [$nadia, $registration, $url] = mailTemplateRegistration();

    $mail = mailTemplateSend($nadia, new InvitationsExpired($registration, 1, true, 2));

    expect($mail->getSubject())->toBe('Tim diteruskan untuk persetujuan: Lomba Karya Tulis Ilmiah Nasional 2026')
        ->and(rtrim((string) $mail->getTextBody()))->toBe(implode("\n", [
            'Halo Nadia,',
            '',
            '1 undangan tim Lomba Karya Tulis Ilmiah Nasional 2026 tidak dijawab sampai batas pendaftaran dan sudah tidak berlaku. Tim 2 orang diteruskan untuk persetujuan.',
            '',
            'Lihat pendaftaran: '.$url,
            '',
            '--',
            'Email otomatis dari SMAGA Talent Hub, SMA Negeri 3 Semarang. Jangan balas email ini.',
        ]));
});

it('keeps the text part clean for a mail with a button and a line after it, and for a mail without a button', function (): void {
    [$nadia] = mailTemplateRegistration();
    $signed = 'http://127.0.0.1:8080/akun/bukan-saya/01jabc?expires=1790000000&signature=0123abcd';

    $withButton = (string) mailTemplateSend($nadia, new EmailBindingNotice(EmailBindingNotice::CHANGED_OLD, 'Nadia', 'n•••@gmail.com', $signed, 7))->getTextBody();
    $withoutButton = (string) mailTemplateSend($nadia, new PasswordWasReset)->getTextBody();

    foreach ([$withButton, $withoutButton] as $text) {
        expect(explode("\n", $text)[0])->toBe('Halo Nadia,')
            ->and(preg_match('/^\s*#/m', $text))->toBe(0)
            ->and($text)->not->toContain('](')
            ->and($text)->not->toContain('Hak cipta')
            ->and($text)->not->toContain('©')
            ->and($text)->not->toContain('Jika tombol')
            ->and($text)->not->toContain('Salin alamat')
            ->and($text)->not->toContain('SMAGA Talent Hub: http')
            ->and(preg_match('/&#\d+;|&amp;|&lt;|&gt;|&quot;/', $text))->toBe(0)
            ->and(preg_match('/\n{3,}/', $text))->toBe(0)
            ->and(rtrim($text))->toEndWith("\n\n--\n".PlainMailMessage::FOOTER)
            // The email scenario's leak scan uses these words as its control phrase.
            ->and($text)->toContain('SMAGA Talent Hub');
    }

    expect(substr_count($withButton, $signed))->toBe(1)
        ->and($withButton)->toContain("\n\nBukan saya, bekukan akun: ".$signed."\n\nLink ini berlaku 7 hari")
        ->and($withoutButton)->not->toContain('http')
        ->and($withoutButton)->toContain('baru saja diganti lewat "Lupa kata sandi". Semua perangkat sudah dikeluarkan.');
});

// The HTML part ------------------------------------------------------------------------------------------------------

it('uses the STH theme: one link (the button), the address under it as text, no image, nothing under 13px', function (): void {
    [$nadia, $registration, $url] = mailTemplateRegistration();

    $html = (string) mailTemplateSend($nadia, new InvitationsExpired($registration, 1, true, 2))->getHtmlBody();
    $visible = mailTemplateVisible($html);
    $firstLine = '1 undangan tim Lomba Karya Tulis Ilmiah Nasional 2026 tidak dijawab sampai batas pendaftaran dan sudah tidak berlaku. Tim 2 orang diteruskan untuk persetujuan.';

    expect(config('mail.markdown.theme'))->toBe('sth')
        ->and(config('mail.markdown.paths'))->toBe([resource_path('views/vendor/mail')])
        ->and($html)->toContain('lang="id"')
        // One link: the button. The header is text, the address under the button is text.
        ->and(preg_match_all('~<a\b~i', $html))->toBe(1)
        ->and($html)->toMatch('~<a href="'.preg_quote(e($url), '~').'" class="button button-primary"[^>]*>Lihat pendaftaran</a>~')
        ->and(mailTemplateTargets($html))->toBe([$url])
        ->and($html)->toMatch('~<span class="break-all"[^>]*>'.preg_quote(e($url), '~').'</span>~')
        ->and($visible)->toContain('Tombol "Lihat pendaftaran" tidak bisa dibuka? Salin alamat ini ke browser:'.$url)
        ->and($html)->toMatch('~<td class="header"[^>]*>\s*<span class="brand"[^>]*>SMAGA Talent Hub</span><br>\s*<span class="brand-sub"[^>]*>SMA Negeri 3 Semarang</span>\s*</td>~')
        ->and($visible)->toStartWith('SMAGA Talent Hub SMA Negeri 3 Semarang Halo Nadia, '.$firstLine)
        ->and($visible)->toEndWith(PlainMailMessage::FOOTER)
        ->and(preg_match_all('~<h1\b~i', $html))->toBe(1)
        ->and($html)->toMatch('~<h1[^>]*>Halo Nadia,</h1>~')
        // The inbox preview line: the first sentence, hidden in the opened mail.
        ->and($html)->toMatch('~<div class="preheader" style="[^"]*display: none;[^"]*">'.preg_quote($firstLine, '~').'</div>~')
        // The look: ink button on the cool page, a flat card.
        ->and($html)->toContain('background-color: #1B2A4E')
        ->and($html)->toContain('background-color: #F3F5F8')
        ->and($html)->toContain('border-radius: 10px')
        ->and($html)->not->toContain('box-shadow')
        ->and($html)->not->toContain('gradient')
        ->and($html)->not->toContain('#18181b')
        ->and(preg_match('~<(img|picture|svg|video|iframe|object|embed|link|script|form)\b~i', $html))->toBe(0)
        ->and($html)->not->toContain('@import')
        ->and($html)->not->toContain('url(')
        ->and(mailTemplateSmallestFont($html))->toBeGreaterThanOrEqual(13.0)
        // No salutation line, no copyright line, no fallback sentence of the framework.
        ->and($visible)->not->toContain('Salam')
        ->and($visible)->not->toContain('Hak cipta')
        ->and($visible)->not->toContain('Jika tombol');
});

it('puts no link at all into a mail without an action', function (): void {
    [$nadia] = mailTemplateRegistration();

    $html = (string) mailTemplateSend($nadia, new PasswordWasReset)->getHtmlBody();

    expect(mailTemplateTargets($html))->toBe([])
        ->and(preg_match_all('~<a\b~i', $html))->toBe(0)
        ->and($html)->not->toContain('class="subcopy"')
        ->and($html)->not->toContain('class="action"')
        ->and(mailTemplateVisible($html))->toBe(implode(' ', [
            'SMAGA Talent Hub SMA Negeri 3 Semarang',
            'Halo Nadia,',
            'Kata sandi akun SMAGA Talent Hub baru saja diganti lewat "Lupa kata sandi". Semua perangkat sudah dikeluarkan.',
            'Jika tidak merasa menggantinya, segera hubungi Admin atau Kesiswaan.',
            PlainMailMessage::FOOTER,
        ]));
});

it('keeps a signed address whole: once as the button target, once as text, once in the text part', function (): void {
    [$nadia] = mailTemplateRegistration();
    $signed = 'http://127.0.0.1:8080/akun/bukan-saya/01jabc?expires=1790000000&signature=0123abcd';

    $mail = mailTemplateSend($nadia, new EmailBindingNotice(EmailBindingNotice::CHANGED_OLD, 'Nadia', 'n•••@gmail.com', $signed, 7));
    $html = (string) $mail->getHtmlBody();

    expect(mailTemplateTargets($html))->toBe([$signed])
        ->and($html)->toMatch('~<span class="break-all"[^>]*>http://127\.0\.0\.1:8080/akun/bukan-saya/01jabc\?expires=1790000000&amp;signature=0123abcd</span>~')
        ->and(substr_count(mailTemplateVisible($html), $signed))->toBe(1)
        ->and(substr_count((string) $mail->getTextBody(), $signed))->toBe(1);
});

it('falls back to the neutral greeting when a mail sets none, and has no "Halo!" or sign-off left', function (): void {
    $message = (new PlainMailMessage)->subject('Uji')->line('Baris pertama.');
    $markdown = app(Markdown::class);

    $html = (string) $markdown->render('notifications::email', $message->data());
    $text = (string) $markdown->renderText('notifications::email', $message->data());

    expect($html)->toMatch('~<h1[^>]*>Halo,</h1>~')
        ->and(explode("\n", $text)[0])->toBe(MailGreeting::NEUTRAL)
        ->and($html.$text)->not->toContain('Halo!')
        ->and($html.$text)->not->toContain('Salam,');

    // A sign-off a notification still sets is not printed: the footer names the sender.
    $signed = (new PlainMailMessage)->greeting('Halo Nadia,')->line('Baris.')->salutation('Hormat kami, Panitia');

    expect((string) $markdown->render('notifications::email', $signed->data()))->not->toContain('Hormat kami')
        ->and((string) $markdown->renderText('notifications::email', $signed->data()))->not->toContain('Hormat kami');
});

// User text stays text, in the greeting too, however the views were compiled -------------------------------------------

dataset('hostile mail text', [
    'link and image' => ['[Masuk ulang di sini](https://evil.example/login) ![x](https://evil.example/p.png)'],
    'backslash before the bracket' => ['\[Masuk](https://evil.example/login) !\[x](https://evil.example/p.png)'],
    'two backslashes' => ['\\\\[Masuk](https://evil.example/login)'],
    'autolink and raw HTML' => ['<https://evil.example/a> <a href="https://evil.example/b">b</a> <img src="https://evil.example/p.png">'],
    'reference link' => ['[Masuk][m] [m]: https://evil.example/login'],
    'character references' => ['&#91;Masuk&#93;(https://evil.example/login) &lt;a href=https://evil.example/c&gt;c&lt;/a&gt;'],
    'formatting' => ['# Judul **tebal** _miring_ `kode` 1. satu - dua | a | b |'],
    'closes the heading and the card' => ['</h1></td></tr></table><h1>x</h1><script>alert(1)</script>'],
]);

dataset('compile order', [
    'compiled beforehand with the ordinary echo, as view:cache does in production' => ['view:cache'],
    'HTML part first (a mail sent on a fresh stack)' => ['html'],
    'text part first' => ['text'],
]);

it('keeps a hostile name in the greeting and hostile text in the lines as text', function (string $order, string $hostile): void {
    mailTemplateFreshCompiledViews();

    $markdown = app(Markdown::class);
    $view = 'notifications::email';

    if ($order === 'view:cache') {
        foreach (mailTemplateViewFiles() as $file) {
            app('blade.compiler')->compile($file);
        }
    }

    // The greeting as MailGreeting builds it for a staff account whose stored name is the hostile text.
    $message = (new PlainMailMessage)
        ->subject('Uji')
        ->greeting(MailGreeting::staff($hostile))
        ->line($hostile.' mengusulkan '.$hostile.'.')
        ->action('Buka antrean', route('kesiswaan.lomba.index', ['tab' => 'verifikasi']))
        ->line($hostile);

    if ($order === 'text') {
        $text = (string) $markdown->renderText($view, $message->data());
        $html = (string) $markdown->render($view, $message->data());
    } else {
        $html = (string) $markdown->render($view, $message->data());
        $text = (string) $markdown->renderText($view, $message->data());
    }

    foreach (mailTemplateTargets($html) as $target) {
        expect($target)->toStartWith((string) config('app.url'));
    }

    // Laravel removes the backslash of "\[" from the lines of the finished HTML part (they went through Markdown);
    // the greeting is a ready heading and stays exactly as typed, and so does the whole text part.
    $shown = str_replace('\[', '[', $hostile);

    expect(mailTemplateTargets($html))->toBe([route('kesiswaan.lomba.index', ['tab' => 'verifikasi'])])
        ->and(preg_match_all('~<a\b~i', $html))->toBe(1)
        ->and($html)->not->toContain('evil.example"')
        ->and(preg_match('~<(img|strong|em|code|ol|ul|li|th|del|blockquote|hr|script|iframe|form)\b~i', $html))->toBe(0)
        ->and(preg_match_all('~<h1\b~i', $html))->toBe(1)
        ->and(preg_match_all('~<table\b~i', $html))->toBe(8)
        ->and(mailTemplateVisible($html))->toContain('Yth. '.$hostile.', '.$shown.' mengusulkan '.$shown.'. Buka antrean '.$shown.' Tombol "Buka antrean"')
        ->and(explode("\n", $text)[0])->toBe('Yth. '.$hostile.',')
        ->and($text)->toContain("\n\n".$hostile.' mengusulkan '.$hostile.".\n\nBuka antrean: ")
        ->and(rtrim($text))->toEndWith("\n\n".$hostile."\n\n--\n".PlainMailMessage::FOOTER);
})->with('compile order')->with('hostile mail text');

it('keeps a mail that was not built with PlainMailMessage inert as well, in every compile order', function (string $order): void {
    mailTemplateFreshCompiledViews();

    $markdown = app(Markdown::class);
    $view = 'notifications::email';
    $hostile = '[Masuk](https://evil.example/login) <b>tebal</b> ![x](https://evil.example/p.png)';

    if ($order === 'view:cache') {
        foreach (mailTemplateViewFiles() as $file) {
            app('blade.compiler')->compile($file);
        }
    }

    $message = (new MailMessage)->greeting($hostile)->line($hostile)->action('Buka', url('/beranda'))->line($hostile);

    if ($order === 'text') {
        $markdown->renderText($view, $message->data());
    }

    $html = (string) $markdown->render($view, $message->data());

    expect(mailTemplateTargets($html))->toBe([url('/beranda')])
        ->and($html)->not->toContain('evil.example"')
        ->and(preg_match('~<(img|b|strong)\b~i', $html))->toBe(0)
        ->and(substr_count(mailTemplateVisible($html), $hostile))->toBe(3);
})->with('compile order');

it('compiles every mail view with the ordinary Blade echo (what view:cache does at deploy)', function (): void {
    mailTemplateFreshCompiledViews();

    $files = mailTemplateViewFiles();
    $published = array_values(array_filter($files, fn (string $file): bool => str_starts_with($file, resource_path('views/vendor'))));

    expect($files[0])->toBe(resource_path('views/vendor/notifications').'/email.blade.php')
        ->and(array_map(fn (string $file): string => str_replace(resource_path('views/vendor').'/', '', $file), $published))->toEqualCanonicalizing([
            'notifications/email.blade.php',
            'mail/html/fallback.blade.php',
            'mail/html/greeting.blade.php',
            'mail/html/header.blade.php',
            'mail/html/layout.blade.php',
            'mail/html/message.blade.php',
            'mail/text/fallback.blade.php',
            'mail/text/greeting.blade.php',
            'mail/text/layout.blade.php',
            'mail/text/message.blade.php',
        ]);

    foreach ($files as $file) {
        app('blade.compiler')->compile($file);

        expect(is_file(app('blade.compiler')->getCompiledPath($file)))->toBeTrue();
    }
});

it('folds a line break in a greeting into one heading line', function (): void {
    $message = (new PlainMailMessage)->greeting("Yth. Nadia\n\n# Putri\r\n\tAnindya,")->line('Baris.');
    $markdown = app(Markdown::class);

    $html = (string) $markdown->render('notifications::email', $message->data());
    $text = (string) $markdown->renderText('notifications::email', $message->data());

    expect($html)->toMatch('~<h1[^>]*>Yth\. Nadia # Putri Anindya,</h1>~')
        ->and(preg_match_all('~<h1\b~i', $html))->toBe(1)
        ->and(explode("\n", $text)[0])->toBe('Yth. Nadia # Putri Anindya,')
        ->and((new PlainMailMessage)->greeting("  \n ")->greeting)->toBeNull();
});

// The OTP mail: plain Blade, same greeting rule, card and footer ---------------------------------------------------------

it('gives the OTP mail the greeting rule, the header and the footer sentence of the notification mails', function (): void {
    [$nadia] = mailTemplateRegistration();
    $kepsek = F::staff(RoleName::KepalaSekolah, 'Drs. Bambang Hartono, M.Pd.');

    $notification = (string) mailTemplateSend($nadia, new PasswordWasReset)->getHtmlBody();

    foreach ([[$nadia, 'Halo Nadia,'], [$kepsek, 'Yth. Drs. Bambang Hartono, M.Pd.,']] as [$user, $greeting]) {
        Mail::to('penerima@contoh.test')->sendNow(new OtpCodeMail('Nadia', '482913', OtpCodeMail::PURPOSE_RESET_PASSWORD, 10, MailGreeting::for($user)));

        $mail = mailTemplateLastMail();
        $html = (string) $mail->getHtmlBody();
        $text = (string) $mail->getTextBody();

        expect($mail->getSubject())->toBe('Kode atur ulang kata sandi')
            ->and($html)->toMatch('~<h1[^>]*>'.preg_quote($greeting, '~').'</h1>~')
            ->and(mailTemplateVisible($html))->toBe(implode(' ', [
                'SMAGA Talent Hub SMA Negeri 3 Semarang',
                $greeting,
                'Ada permintaan atur ulang kata sandi SMAGA Talent Hub. Masukkan kode ini:',
                '482913',
                'Kode berlaku 10 menit dan hanya bisa dipakai sekali.',
                'Jika tidak merasa memintanya, abaikan email ini dan hubungi Kesiswaan.',
                PlainMailMessage::FOOTER,
            ]))
            // The inbox preview line is the sentence, never the code.
            ->and($html)->toMatch('~<div class="preheader" style="display:none;[^"]*">Ada permintaan atur ulang kata sandi SMAGA Talent Hub\.</div>~')
            ->and(mailTemplateTargets($html))->toBe([])
            ->and(preg_match('~<(a|img|script|form)\b~i', $html))->toBe(0)
            ->and($html)->not->toContain('box-shadow')
            ->and($html)->not->toContain('gradient')
            ->and(mailTemplateSmallestFont($html))->toBeGreaterThanOrEqual(13.0)
            ->and(rtrim($text))->toBe(implode("\n", [
                $greeting,
                '',
                'Ada permintaan atur ulang kata sandi SMAGA Talent Hub. Masukkan kode ini:',
                '',
                '482913',
                '',
                'Kode berlaku 10 menit dan hanya bisa dipakai sekali.',
                'Jika tidak merasa memintanya, abaikan email ini dan hubungi Kesiswaan.',
                '',
                '--',
                PlainMailMessage::FOOTER,
            ]));

        // Same card as the notification mail: the values of the theme, repeated by hand in the OTP view.
        foreach (['#F3F5F8', '#DDE2E9', '#1B2A4E', '#566072', 'border-radius:10px', 'width:570px', "'Public Sans'"] as $value) {
            expect($html)->toContain($value)
                ->and(str_replace(': ', ':', $notification))->toContain($value);
        }
    }
});

it('still renders an OTP mail built the old way (four arguments) and one queued before the greeting existed', function (): void {
    Mail::to('nadia@contoh.test')->sendNow(new OtpCodeMail("Ma'ruf", '482913', OtpCodeMail::PURPOSE_VERIFY_EMAIL, 10));

    $mail = mailTemplateLastMail();

    expect((string) $mail->getHtmlBody())->toContain('>Halo Ma&#039;ruf,</h1>')
        ->and(explode("\n", (string) $mail->getTextBody())[0])->toBe("Halo Ma'ruf,");

    // A job from before the deploy has no "greeting" in its payload: the property keeps its default.
    $old = mailTemplatePayloadWithout(new OtpCodeMail('Nadia', '482913', OtpCodeMail::PURPOSE_VERIFY_EMAIL, 10), 'greeting');

    /** @var OtpCodeMail $queued */
    $queued = unserialize($old);

    expect($old)->not->toContain('"greeting"')
        ->and($queued)->toBeInstanceOf(OtpCodeMail::class)
        ->and($queued->greeting)->toBeNull()
        ->and($queued->render())->toContain('>Halo Nadia,</h1>');
});

it('still sends an email binding notice that was queued before the greeting existed', function (): void {
    $signed = 'http://127.0.0.1:8080/akun/bukan-saya/01jabc?expires=1790000000&signature=0123abcd';

    $old = mailTemplatePayloadWithout(new EmailBindingNotice(EmailBindingNotice::BOUND, 'Nadia', 'n•••@gmail.com', $signed, 7), 'greeting');

    /** @var EmailBindingNotice $queued */
    $queued = unserialize($old);

    expect($old)->not->toContain('"greeting"')
        ->and($queued)->toBeInstanceOf(EmailBindingNotice::class)
        ->and($queued->greeting)->toBeNull()
        ->and(html_entity_decode((string) $queued->toMail(Notification::route('mail', 'nadia@contoh.test'))->greeting, ENT_QUOTES | ENT_HTML5))->toBe('Halo Nadia,');
});

it('ends the sth:mail-test mail with the same footer sentence', function (): void {
    $this->artisan('sth:mail-test', ['to' => 'uji@example.test'])->assertSuccessful();

    expect(rtrim((string) mailTemplateLastMail()->getTextBody()))->toEndWith("\n".PlainMailMessage::FOOTER)
        ->and(PlainMailMessage::FOOTER)->toBe('Email otomatis dari SMAGA Talent Hub, SMA Negeri 3 Semarang. Jangan balas email ini.');
});
