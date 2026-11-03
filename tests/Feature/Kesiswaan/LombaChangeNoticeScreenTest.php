<?php

declare(strict_types=1);

use App\Actions\Competitions\CloseCompetition;
use App\Actions\Competitions\CompetitionChange;
use App\Actions\Competitions\UpdateCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Akun\Notifikasi;
use App\Livewire\Kesiswaan\Lomba\Form;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Competitions\BinaanCompetitionChanged;
use App\Notifications\Competitions\CompetitionChanged;
use App\Notifications\Competitions\CompetitionPublished;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Kesiswaan\LombaChangeFixtures as C;
use Tests\Support\TalentGate;

/*
 * The "published lomba changed" notice (lead's mail test of 2026-10-09, finding 10-7, D-126) as people meet it:
 * the Kesiswaan drawer says beforehand who will be told and afterwards who was, the student and the guru get a
 * mail and a bell row that hold plain sentences and never a web address, and the bell row still opens after the
 * lomba was closed. The rule itself: LombaChangeNoticeTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
    F::boot();
    config(['mail.default' => 'array']);
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

// The hint follows the rule of CompetitionChange::statusesFor(): batas daftar and peserta are the team's own matter.
const LCN_HINT = 'siswa sedang terdaftar di lomba ini. Perubahan tanggal, tempat, pelaksanaan, link, atau juknis diberitahukan ke siswa itu dan guru pembimbingnya.'
    .' Perubahan batas daftar atau peserta hanya diberitahukan ke tim yang masih menunggu konfirmasi anggota.';

/**
 * A team of three still collecting answers (ketua Raka, Nadia confirmed, Hana invited) with guru Pak Hendra.
 *
 * @return array{competition: Competition, registration: Registration, ketua: User, confirmed: User, invited: User, guru: User}
 */
function lcnScreenTeam(RegistrationStatus $status = RegistrationStatus::MenungguKonfirmasi, array $lomba = []): array
{
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $ketua = F::student('Raka Pradipta', 'XII-B');
    $confirmed = F::student('Nadia Putri Anindya', 'XI-D');
    $invited = F::student('Hana Safira', 'X-H');
    TalentGate::pass($invited); // D-144: a kelas X student opens the siswa pages once she has a talent

    $competition = C::lomba($lomba);
    $registration = C::registration($competition, $status, $ketua, $guru);
    C::member($registration, $confirmed);
    C::member($registration, $invited, 'invited');

    return [
        'competition' => $competition, 'registration' => $registration,
        'ketua' => $ketua->user, 'confirmed' => $confirmed->user, 'invited' => $invited->user, 'guru' => $guru,
    ];
}

/** @return list<Email> every mail the array mailer sent in this test */
function lcnScreenMails(): array
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
        ->map(fn ($sent): Email => $sent->getOriginalMessage())
        ->values()
        ->all();
}

/** @return list<Email> the mails addressed to that account */
function lcnScreenMailsTo(User $user): array
{
    return array_values(array_filter(
        lcnScreenMails(),
        fn (Email $mail): bool => in_array($user->email, array_map(fn ($address): string => $address->getAddress(), $mail->getTo()), true),
    ));
}

/** Every address the HTML part points at (links, images, forms, frames). @return list<string> */
function lcnScreenTargets(string $html): array
{
    preg_match_all('~<[a-z0-9]+\b[^>]*?\s(?:href|src|action|background)\s*=\s*(["\'])(.*?)\1~is', $html, $matches);

    return array_values(array_unique(array_map(fn (string $url): string => html_entity_decode($url, ENT_QUOTES | ENT_HTML5), $matches[2])));
}

/** The text a reader sees in the HTML part. */
function lcnScreenVisible(string $html): string
{
    $body = (string) preg_replace('~<(style|head)\b.*?</\1>~is', '', $html);

    return trim((string) preg_replace('~\s+~', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)));
}

// The drawer: who was told ---------------------------------------------------------------------------------------

it('names the students and the guru pembimbing in the toast when they were told', function (): void {
    Notification::fake();
    $team = lcnScreenTeam();

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $team['competition']->getKey())
        ->assertSet('formMode', 'edit')
        ->set('form.location', 'Aula SMA Negeri 3 Semarang')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan. 3 siswa dan 1 guru pembimbing diberi tahu.', tone: 'success');

    Notification::assertSentTo([$team['ketua'], $team['confirmed'], $team['invited']], CompetitionChanged::class);
    Notification::assertSentTo($team['guru'], BinaanCompetitionChanged::class);
});

it('names only the students when the change is the team\'s own matter', function (): void {
    Notification::fake();
    $team = lcnScreenTeam();

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $team['competition']->getKey())
        ->set('form.registration_closes_at', '2026-10-09')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan. 3 siswa diberi tahu.', tone: 'success');

    Notification::assertNothingSentTo($team['guru']);
});

it('names only the guru pembimbing when no student account can be told', function (): void {
    Notification::fake();
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $ketua = F::student('Raka Pradipta', 'XII-B');
    $ketua->user->forceFill(['status' => UserStatus::Disabled])->save();
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::SuratTerbit, $ketua, $guru, RegistrationType::Individu);

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->set('form.mode', 'daring')
        ->call('save')
        ->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan. 1 guru pembimbing diberi tahu.', tone: 'success');
});

it('keeps the plain toast when nobody was told', function (array $change, bool $registered): void {
    Notification::fake();
    $competition = $registered ? lcnScreenTeam()['competition'] : C::lomba();

    $component = Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $competition->getKey());

    foreach ($change as $field => $value) {
        $component->set('form.'.$field, $value);
    }

    $component->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan.', tone: 'success');

    Notification::assertNothingSent();
})->with([
    'nothing changed' => [[], true],
    'a correction of the name' => [['name' => 'National Science and Invention Fair 2026'], true],
    'a new place, nobody registered' => [['location' => 'Aula SMA Negeri 3 Semarang'], false],
]);

it('says "tanpa email" in the toast when the change reached more people than the mail limit', function (): void {
    Notification::fake();
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $competition = C::lomba();

    for ($i = 1; $i <= CompetitionChange::MAIL_LIMIT; $i++) {
        C::registration($competition, RegistrationStatus::Disetujui, F::student('Siswa Contoh '.$i, 'X-A'), $guru, RegistrationType::Individu);
    }

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->assertSee('50 '.LCN_HINT)
        ->set('form.location', 'Aula SMA Negeri 3 Semarang')
        ->call('save')
        ->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan. 50 siswa dan 1 guru pembimbing diberi tahu lewat notifikasi di aplikasi, tanpa email.', tone: 'success');

    Notification::assertSentTo($guru, BinaanCompetitionChanged::class, fn (BinaanCompetitionChanged $n, array $channels): bool => $channels === ['database']);
});

it('tells nobody while the lomba waits in the queue, also through "Simpan & publikasikan"', function (): void {
    Notification::fake();
    $submitter = F::staff(RoleName::Guru, 'Bu Retno Wulandari');
    $ketua = F::student('Raka Pradipta', 'XII-B');
    $competition = Competition::factory()->create([
        ...F::form(['registration_opens_at' => '2026-09-15 00:00:00', 'registration_closes_at' => '2026-10-20 23:59:59']),
        'name' => 'Lomba Esai Fiktif 2026', 'submitted_by' => $submitter->getKey(),
    ]);
    C::registration($competition, RegistrationStatus::MenungguKonfirmasi, $ketua, F::staff(RoleName::Guru, 'Pak Hendra Kusuma'));

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->assertSet('formMode', 'verify')
        ->assertDontSee('sedang terdaftar di lomba ini')
        ->set('form.location', 'Aula SMA Negeri 3 Semarang')
        ->call('save')
        ->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan.', tone: 'success');

    Notification::assertNothingSent();

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->set('form.registration_closes_at', '2026-10-25')
        ->set('form.mode', 'luring')
        ->call('saveAndPublish')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Lomba dipublikasikan ke katalog.', tone: 'success');

    expect($competition->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and(C::auditRows())->toBe([]);

    Notification::assertSentTo($submitter, CompetitionPublished::class);
    Notification::assertNothingSentTo($ketua->user);
    Notification::assertCount(1);
});

it('sends nothing and shows the field error when the drawer save is refused', function (): void {
    Notification::fake();
    $team = lcnScreenTeam();

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $team['competition']->getKey())
        ->set('form.location', 'Aula SMA Negeri 3 Semarang')
        ->set('form.max_team_size', 2)
        ->call('save')
        ->assertHasErrors(['form.max_team_size'])
        ->assertNotDispatched('sth-toast');

    Notification::assertNothingSent();
    expect($team['competition']->fresh()->location)->toBe('Kota Semarang');
});

// The drawer: who will be told -----------------------------------------------------------------------------------

it('says at the top of "Ubah lomba" how many registered students a change is told to', function (): void {
    $team = lcnScreenTeam();
    // Not counted: a member who declined, and registrations that no longer rely on the lomba's data.
    C::member($team['registration'], F::student('Rizky Maulana', 'XI-A'), 'declined');
    C::registration($team['competition'], RegistrationStatus::MenungguLaporan, F::student('Dimas Arya Saputra', 'XI-B'));
    C::registration($team['competition'], RegistrationStatus::Selesai, F::student('Salsabila Azzahra', 'XI-C'));
    C::registration($team['competition'], RegistrationStatus::Dibatalkan, F::student('Bagas Wicaksono', 'XI-E'));
    // Counted: another registration that is still on its way to the lomba.
    C::registration($team['competition'], RegistrationStatus::Berlangsung, F::student('Citra Lestari', 'XI-F'));

    expect(CompetitionChange::attachedStudents($team['competition']))->toBe(4);

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $team['competition']->getKey())
        ->assertSet('formMode', 'edit')
        ->assertSee('4 '.LCN_HINT);
});

it('shows no hint for a new lomba, a lomba in the queue or a lomba nobody is registered for', function (): void {
    $empty = C::lomba();
    $closed = lcnScreenTeam()['competition'];

    // "Tambah lomba" is Admin only (D-145).
    Livewire::actingAs(F::staff(RoleName::Admin, 'Operator STH'))->test(Form::class)
        ->assertDontSee('sedang terdaftar di lomba ini')
        ->call('open')
        ->assertSet('formMode', 'create')
        ->assertDontSee('sedang terdaftar di lomba ini')
        ->call('open', $empty->getKey())
        ->assertSet('formMode', 'edit')
        ->assertDontSee('sedang terdaftar di lomba ini');

    app(CloseCompetition::class)->handle($this->kesiswaan, $closed);

    expect(CompetitionChange::attachedStudents($closed->fresh()))->toBe(0);
});

// What arrives ---------------------------------------------------------------------------------------------------

it('delivers a bell row and a mail with one sentence per line, and never the new web address', function (): void {
    $team = lcnScreenTeam(RegistrationStatus::Disetujui);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $team['competition'], C::input($team['competition'], [
        'starts_on' => '2026-11-08', 'final_on' => '2026-11-10', 'location' => 'Aula SMA Negeri 3 Semarang',
        'registration_url' => 'https://example.org/nsif/daftar-baru', 'website_url' => '',
    ]));

    $sentences = [
        'Tanggal lomba sekarang 8–10 Nov 2026.',
        'Tempat sekarang Aula SMA Negeri 3 Semarang.',
        'Link registrasi diganti.',
        'Link resmi dihapus.',
    ];

    // The bell: one row per person, kind + title + one paragraph + the link to the own registration / binaan page
    // (stored as a path, App\Notifications\Channels\BellChannel).
    $row = $team['ketua']->notifications()->sole();
    $guruRow = $team['guru']->notifications()->sole();

    expect($row->type)->toBe(CompetitionChanged::class)
        ->and($row->data)->toMatchArray([
            'kind' => 'competition.changed',
            'title' => 'Info lomba berubah: '.C::NAME,
            'body' => 'Kesiswaan mengubah data lomba ini. '.implode(' ', $sentences),
            'url' => route('siswa.lomba-saya.show', ['registration' => $team['registration']->getKey()], false),
            'registration_id' => $team['registration']->getKey(),
        ])
        ->and($guruRow->type)->toBe(BinaanCompetitionChanged::class)
        ->and($guruRow->data)->toMatchArray([
            'kind' => 'competition.binaan_changed',
            'title' => 'Info lomba binaan berubah: '.C::NAME,
            'body' => 'Kesiswaan mengubah data lomba yang diikuti siswa binaan. '.implode(' ', $sentences),
            'url' => route('guru.binaan.show', ['registration' => $team['registration']->getKey()], false),
        ])
        ->and(DatabaseNotification::query()->count())->toBe(4)
        ->and($this->kesiswaan->notifications()->count())->toBe(0);

    // The mail: four people, one mail each.
    expect(lcnScreenMails())->toHaveCount(4);

    $mail = lcnScreenMailsTo($team['ketua'])[0];
    $text = (string) $mail->getTextBody();

    expect($mail->getSubject())->toBe('Info lomba berubah: '.C::NAME)
        ->and($text)->toStartWith("Halo Raka,\n")
        ->and($text)->toContain("Kesiswaan mengubah data lomba ini.\n")
        ->and($text)->toContain('Lihat lomba: '.route('siswa.lomba-saya.show', ['registration' => $team['registration']->getKey()]));

    foreach ($sentences as $sentence) {
        expect($text)->toContain($sentence."\n")
            ->and(lcnScreenVisible((string) $mail->getHtmlBody()))->toContain($sentence);
    }

    $guruMail = lcnScreenMailsTo($team['guru'])[0];
    $guruText = (string) $guruMail->getTextBody();

    expect($guruMail->getSubject())->toBe('Info lomba binaan berubah: '.C::NAME)
        ->and($guruText)->toStartWith("Yth. Pak Hendra Kusuma,\n")
        ->and($guruText)->toContain("Kesiswaan mengubah data lomba yang diikuti siswa binaan.\n")
        ->and($guruText)->toContain('Lihat binaan: '.route('guru.binaan.show', ['registration' => $team['registration']->getKey()]))
        ->and($guruText)->not->toContain('kamu');

    // No mail and no bell row carries a lomba address, old or new (SECURITY §3m-1).
    foreach (lcnScreenMails() as $sent) {
        expect((string) $sent->getTextBody().(string) $sent->getHtmlBody())->not->toContain('example.org');

        foreach (lcnScreenTargets((string) $sent->getHtmlBody()) as $target) {
            expect($target)->toStartWith((string) config('app.url'));
        }
    }

    expect((string) json_encode(DatabaseNotification::query()->pluck('data')))->not->toContain('example.org');
});

it('says only that the place changed, in the mail and in the bell, when the new place holds a web address', function (): void {
    $team = lcnScreenTeam(RegistrationStatus::Disetujui);

    // The save itself is not refused: "Tempat" has no address rule, only the notice leaves the value out.
    app(UpdateCompetition::class)->handle($this->kesiswaan, $team['competition'], C::input($team['competition'], [
        'mode' => 'daring', 'location' => 'Zoom, zoom.us/j/123456',
    ]));

    expect($team['competition']->fresh()->location)->toBe('Zoom, zoom.us/j/123456')
        ->and(lcnScreenMails())->toHaveCount(4)
        ->and(DatabaseNotification::query()->count())->toBe(4);

    foreach (lcnScreenMails() as $sent) {
        $text = (string) $sent->getTextBody();

        expect($text)->toContain("Pelaksanaan sekarang Daring.\n")
            ->and($text)->toContain("Tempat diganti.\n")
            ->and(lcnScreenVisible((string) $sent->getHtmlBody()))->toContain('Tempat diganti.')
            ->and($text.(string) $sent->getHtmlBody())->not->toContain('zoom.us');
    }

    foreach ([$team['ketua'], $team['guru']] as $account) {
        expect($account->notifications()->sole()->data['body'])->toEndWith('Pelaksanaan sekarang Daring. Tempat diganti.');
    }

    expect((string) json_encode(DatabaseNotification::query()->pluck('data')))->not->toContain('zoom.us');
});

it('stores the bell row but sends no mail to a frozen account or a member who has not activated', function (): void {
    $team = lcnScreenTeam(RegistrationStatus::Disetujui);
    $team['confirmed']->forceFill(['frozen_at' => now(), 'frozen_reason' => 'failed_logins'])->save();
    $team['invited']->forceFill(['status' => UserStatus::PendingActivation, 'email' => null, 'email_verified_at' => null])->save();

    app(UpdateCompetition::class)->handle($this->kesiswaan, $team['competition'], C::input($team['competition'], ['mode' => 'daring']));

    expect($team['confirmed']->notifications()->count())->toBe(1)
        ->and($team['invited']->notifications()->count())->toBe(1)
        ->and(lcnScreenMails())->toHaveCount(2)
        ->and(lcnScreenMailsTo($team['ketua']))->toHaveCount(1)
        ->and(lcnScreenMailsTo($team['guru']))->toHaveCount(1);
});

it('sends bell rows only, no mail at all, above the mail limit', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $competition = C::lomba();

    for ($i = 1; $i <= CompetitionChange::MAIL_LIMIT; $i++) {
        C::registration($competition, RegistrationStatus::Disetujui, F::student('Siswa Contoh '.$i, 'X-A'), $guru, RegistrationType::Individu);
    }

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['mode' => 'daring']));

    expect(DatabaseNotification::query()->count())->toBe(CompetitionChange::MAIL_LIMIT + 1)
        ->and(lcnScreenMails())->toBe([]);
});

it('opens the student\'s own registration from the bell, also after the lomba was closed', function (): void {
    $team = lcnScreenTeam();

    app(UpdateCompetition::class)->handle($this->kesiswaan, $team['competition'], C::input($team['competition'], ['registration_closes_at' => '2026-10-09']));
    app(CloseCompetition::class)->handle($this->kesiswaan, $team['competition']->fresh());

    // The still-invited member: Detail lomba would answer 404 now, Lomba saya does not.
    $row = $team['invited']->notifications()->sole();
    $path = '/siswa/lomba-saya/'.$team['registration']->getKey();

    Livewire::actingAs($team['invited'])->test(Notifikasi::class)
        ->assertSee('Info lomba berubah: '.C::NAME)
        ->assertSee('Batas daftar sekarang 9 Okt 2026.')
        ->call('open', $row->getKey())
        ->assertRedirect($path);

    $this->actingAs($team['invited'])->get($path)->assertOk()->assertSee(C::NAME);
});

// Plain text ------------------------------------------------------------------------------------------------------

it('keeps the lomba name and a typed sentence as plain text in both mails', function (string $hostile): void {
    $team = lcnScreenTeam(RegistrationStatus::Disetujui, ['name' => $hostile, 'location' => $hostile]);
    // Built by hand: CompetitionChange::line() leaves out a place that holds an address ("Tempat diganti."), and this
    // test is about the notifications, which must show whatever sentence they are given as text.
    $lines = ['Tempat sekarang '.$hostile.'.'];

    $cases = [
        [$team['ketua'], CompetitionChanged::for($team['competition'], $team['registration']->getKey(), $lines)],
        [$team['guru'], BinaanCompetitionChanged::for($team['competition'], $lines, $team['registration']->getKey())],
    ];

    foreach ($cases as [$notifiable, $notification]) {
        Notification::sendNow($notifiable, $notification, ['mail']);

        $mail = collect(lcnScreenMails())->last();
        $html = (string) $mail->getHtmlBody();

        foreach (lcnScreenTargets($html) as $target) {
            expect($target)->toStartWith((string) config('app.url'));
        }

        // Laravel removes the backslash of "\[" from the finished HTML part; the text part keeps it.
        expect($html)->not->toContain('evil.example"')
            ->and(preg_match('~<(img|strong|em|code|ol|ul|li|th|del|blockquote|hr|script|iframe|form)\b~i', $html))->toBe(0)
            ->and(substr_count($html, '<h1'))->toBeLessThanOrEqual(1)
            ->and(lcnScreenVisible($html))->toContain(str_replace('\[', '[', $hostile))
            ->and((string) $mail->getTextBody())->toContain($hostile);
    }
})->with([
    'link and image' => ['[Masuk ulang di sini](https://evil.example/login) ![x](https://evil.example/p.png)'],
    'backslash before the bracket' => ['\[Masuk](https://evil.example/login) !\[x](https://evil.example/p.png)'],
    'autolink and raw HTML' => ['<https://evil.example/a> <a href="https://evil.example/b">b</a> <img src="https://evil.example/p.png">'],
    'reference link' => ['[Masuk][m] [m]: https://evil.example/login'],
    'formatting' => ['# Judul **tebal** _miring_ `kode` 1. satu - dua | a | b |'],
]);
