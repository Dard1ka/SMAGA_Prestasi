<?php

declare(strict_types=1);

use App\Actions\Competitions\SubmitCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Lomba\UsulanForm;
use App\Models\Competition;
use App\Models\File;
use App\Models\User;
use App\Notifications\Competitions\CompetitionSubmitted;
use App\Rules\ExternalUrl;
use App\Rules\NoWebAddress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Staf Kesiswaan');
    $this->admin = F::staff(RoleName::Admin, 'Operator');
    $this->nadia = F::student();
});

it('stores a student proposal as menunggu_verifikasi and tells the verifiers (D-27)', function (): void {
    $competition = app(SubmitCompetition::class)->handle(F::user($this->nadia), [
        'name' => 'ISPO 2027',
        'organizer_name' => 'Yayasan Contoh',
        'website_url' => 'https://ispo.example.id/info',
        'description' => 'Pendaftaran Januari.',
    ]);

    $fresh = $competition->fresh();
    expect($fresh->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and((int) $fresh->submitted_by)->toBe(F::user($this->nadia)->id)
        ->and($fresh->verified_by)->toBeNull()
        ->and(Competition::query()->published()->count())->toBe(0);

    Notification::assertSentTo([$this->kesiswaan, $this->admin], CompetitionSubmitted::class);
    expect(Activity::query()->where('event', 'competition.submitted')->exists())->toBeTrue();
});

it('refuses a guru usulan (D-145)', function (): void {
    $guru = F::staff(RoleName::Guru);

    expect(fn () => app(SubmitCompetition::class)->handle($guru, ['name' => 'Lomba Debat Kota 2027']))
        ->toThrow(AuthorizationException::class);

    expect(Competition::query()->count())->toBe(0);
});

it('denies users without competition.create or who cannot log in', function (): void {
    $noRole = User::factory()->create()->fresh();
    $disabled = F::user(F::student('Siswa Nonaktif', 'X-1', ['status' => UserStatus::Disabled]));

    expect(fn () => app(SubmitCompetition::class)->handle($noRole, ['name' => 'Lomba A']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SubmitCompetition::class)->handle($disabled, ['name' => 'Lomba A']))->toThrow(AuthorizationException::class);
});

it('accepts only https links without credentials, IPs or odd ports (SECURITY §3m-1)', function (string $url, bool $ok): void {
    expect(ExternalUrl::passes($url))->toBe($ok);
})->with([
    ['https://ispo.example.id/daftar?x=1', true],
    ['https://lomba.go.id:443/a', true],
    ['http://ispo.example.id', false],
    ['javascript:alert(1)', false],
    ['https://user:pass@ispo.example.id', false],
    ['https://127.0.0.1/admin', false],
    ['https://[::1]/', false],
    ['https://2130706433/', false],
    ['https://localhost/', false],
    ['https://ispo.example.id:8081/', false],
    ["https://ispo.example.id/\nx", false],
]);

it('rejects an unsafe link in the Action as well', function (): void {
    expect(fn () => app(SubmitCompetition::class)->handle(F::user($this->nadia), ['name' => 'ISPO 2027', 'website_url' => 'http://ispo.example.id']))
        ->toThrow(ValidationException::class);
    expect(Competition::query()->count())->toBe(0);
});

it('limits proposals to 5 per day per user', function (): void {
    $user = F::user($this->nadia);
    for ($i = 1; $i <= 5; $i++) {
        app(SubmitCompetition::class)->handle($user, ['name' => "Lomba contoh {$i}"]);
    }

    expect(fn () => app(SubmitCompetition::class)->handle($user, ['name' => 'Lomba contoh 6']))->toThrow(ValidationException::class);
    expect(Competition::query()->count())->toBe(5);
});

it('keeps the brochure private on the local disk with a random path', function (): void {
    $pdf = UploadedFile::fake()->createWithContent('Brosur ISPO.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF");

    $competition = app(SubmitCompetition::class)->handle(F::user($this->nadia), ['name' => 'ISPO 2027'], $pdf);

    $file = File::query()->findOrFail($competition->fresh()->juknis_file_id);
    expect($file->purpose)->toBe(FilePurpose::Juknis)
        ->and($file->disk)->toBe('local')
        ->and($file->path)->toMatch('~^lampiran/\d{4}/\d{2}/[0-9a-z]{26}\.pdf$~')
        ->and($file->original_name)->toBe('Brosur ISPO.pdf')
        ->and((int) $file->owner_user_id)->toBe(F::user($this->nadia)->id);
    Storage::disk('local')->assertExists($file->path);
});

it('refuses a PDF with active content and leaves nothing behind', function (): void {
    $pdf = UploadedFile::fake()->createWithContent('brosur.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction 2 0 R >> endobj\n%%EOF");

    expect(fn () => app(SubmitCompetition::class)->handle(F::user($this->nadia), ['name' => 'ISPO 2027'], $pdf))
        ->toThrow(ValidationException::class);
    expect(Competition::query()->count())->toBe(0)->and(File::query()->count())->toBe(0);
});

it('submits through the Usulkan lomba sheet', function (): void {
    $this->actingAs(F::user($this->nadia));

    // Kesiswaan verifies a proposal against the organizer, the official link and the invitation
    // (concept doc section 5), so the sheet asks for all three; only the note stays optional.
    Livewire::test(UsulanForm::class)
        ->call('kirim')
        ->assertHasErrors([
            'name' => 'required',
            'organizer_name' => 'required',
            'website_url' => 'required',
            'brosur' => 'required',
        ])
        ->assertHasNoErrors('description')
        ->set('name', 'ISPO 2027')
        ->set('organizer_name', 'Yayasan Contoh')
        ->set('website_url', 'http://ispo.example.id')
        ->call('kirim')
        ->assertHasErrors(['website_url'])
        ->set('website_url', 'https://ispo.example.id')
        ->call('kirim')
        ->assertHasErrors(['brosur' => 'required'])
        ->set('brosur', UploadedFile::fake()->image('poster.jpg', 1200, 800))
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        // The student's sheet keeps its own words ("Upload info lomba" is the guru's name for it).
        ->assertSee('Usulkan lomba')
        ->assertSee('Usulan terkirim')
        ->assertSee('Usulkan lagi')
        ->assertDontSee('Info lomba terkirim');

    $competition = Competition::query()->sole();
    expect($competition->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and(File::query()->sole()->mime)->toBe('image/jpeg');
});

// The lead's mail test of 2026-10-09: the sheet checked a link as typed and answered every refusal with one sentence.

/** A brochure the sheet and StoreUpload both accept. */
function usulanBrochure(): UploadedFile
{
    return UploadedFile::fake()->image('poster.jpg', 600, 800);
}

it('accepts a link pasted with spaces or a line break around it and stores it clean', function (string $typed): void {
    $this->actingAs(F::user($this->nadia));

    Livewire::test(UsulanForm::class)
        ->set('name', '  ISPO 2027  ')
        ->set('organizer_name', ' Yayasan Contoh ')
        ->set('website_url', $typed)
        ->set('description', "  Pendaftaran Januari.\n")
        ->set('brosur', usulanBrochure())
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertSet('sent', true);

    $competition = Competition::query()->sole();

    expect($competition->website_url)->toBe('https://ispo.example.id/info')
        ->and($competition->name)->toBe('ISPO 2027')
        ->and($competition->organizer_name)->toBe('Yayasan Contoh')
        ->and($competition->description)->toBe('Pendaftaran Januari.');
})->with([
    'space behind' => ['https://ispo.example.id/info '],
    'space in front' => [' https://ispo.example.id/info'],
    'line break behind' => ["https://ispo.example.id/info\r\n"],
    'tab in front' => ["\thttps://ispo.example.id/info"],
    'no-break space' => ["\u{00A0}https://ispo.example.id/info\u{00A0}"],
    'zero-width space' => ["https://ispo.example.id/info\u{200B}"],
]);

it('shows the cleaned link in the field when something else is still missing', function (): void {
    $this->actingAs(F::user($this->nadia));

    Livewire::test(UsulanForm::class)
        ->set('name', 'ISPO 2027')
        ->set('organizer_name', 'Yayasan Contoh')
        ->set('website_url', "  https://ispo.example.id/info \n")
        ->call('kirim')
        ->assertHasErrors(['brosur' => 'required'])
        ->assertHasNoErrors('website_url')
        ->assertSet('website_url', 'https://ispo.example.id/info');

    expect(Competition::query()->count())->toBe(0);
});

it('says what is wrong with the link on the sheet and brings the error into view', function (string $typed, string $sentence): void {
    $this->actingAs(F::user($this->nadia));

    Livewire::test(UsulanForm::class)
        ->set('name', 'ISPO 2027')
        ->set('organizer_name', 'Yayasan Contoh')
        ->set('website_url', $typed)
        ->set('brosur', usulanBrochure())
        ->call('kirim')
        ->assertHasErrors(['website_url'])
        ->assertSee($sentence)
        ->assertDispatched('sth-form-invalid', form: 'usul')
        ->assertSet('sent', false);

    expect(Competition::query()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0);
})->with([
    'http' => ['http://ispo.example.id', ExternalUrl::HTTP],
    'no scheme' => ['ispo.example.id/info', ExternalUrl::NO_HTTPS],
    'comma for the dot' => ['https://ispo,example.id/info', ExternalUrl::HOST],
    'space inside' => ['https://ispo.example.id/info lomba', ExternalUrl::SPACE],
    'ip address' => ['https://127.0.0.1/info', ExternalUrl::IP],
    'odd port' => ['https://ispo.example.id:8081/info', ExternalUrl::PORT],
    'credentials' => ['https://user:rahasia@ispo.example.id/', ExternalUrl::CREDENTIALS],
    'en dash' => ['https://ispo.example.id/info–2027', ExternalUrl::CHARACTERS],
    'too long' => ['https://ispo.example.id/'.str_repeat('a', 480), ExternalUrl::TOO_LONG],
]);

it('tells the student what to copy under the link field', function (): void {
    $this->actingAs(F::user($this->nadia));

    Livewire::test(UsulanForm::class)
        ->assertSee('Salin dari kolom alamat browser, diawali https://. Kesiswaan memeriksa link ini dulu.')
        ->assertSeeHtml('spellcheck="false"')
        ->assertSeeHtml('autocapitalize="off"')
        ->assertSeeHtml('novalidate');
});

it('shows a web address in the name together with the other field errors and keeps the hourly limit untouched', function (): void {
    $user = F::user($this->nadia);
    $this->actingAs($user);

    Livewire::test(UsulanForm::class)
        ->set('name', 'Daftar di www.lomba-fiktif.example')
        ->set('organizer_name', 'Yayasan Contoh')
        ->set('website_url', 'http://ispo.example.id')
        ->call('kirim')
        ->assertHasErrors(['name', 'website_url', 'brosur' => 'required'])
        ->assertSee(NoWebAddress::MESSAGE)
        ->assertSee(ExternalUrl::HTTP)
        ->assertDispatched('sth-form-invalid', form: 'usul');

    expect(RateLimiter::attempts('usulan:'.$user->id))->toBe(0)
        ->and(Competition::query()->count())->toBe(0);
});

it('brings the sixth usulan of the day into view as an error too', function (): void {
    $user = F::user($this->nadia);
    Competition::factory()->proposal()->count(SubmitCompetition::DAILY_LIMIT)->create(['submitted_by' => $user->id]);
    $files = File::query()->count();
    $this->actingAs($user);

    Livewire::test(UsulanForm::class)
        ->set('name', 'Lomba contoh keenam')
        ->set('organizer_name', 'Yayasan Contoh')
        ->set('website_url', 'https://ispo.example.id')
        ->set('brosur', usulanBrochure())
        ->call('kirim')
        ->assertHasErrors(['name'])
        ->assertSee('Sudah 5 kali mengirim hari ini. Coba lagi besok.')
        ->assertDispatched('sth-form-invalid', form: 'usul')
        ->assertSet('sent', false);

    expect(Competition::query()->count())->toBe(SubmitCompetition::DAILY_LIMIT)
        ->and(File::query()->count())->toBe($files);
});

it('brings a brochure the Action refuses into view as an error too', function (): void {
    $this->actingAs(F::user($this->nadia));

    // Passes the sheet's own file rules (a PDF by name and content), refused by StoreUpload (active content).
    $pdf = UploadedFile::fake()->createWithContent('brosur.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction 2 0 R >> endobj\n%%EOF");

    Livewire::test(UsulanForm::class)
        ->set('name', 'ISPO 2027')
        ->set('organizer_name', 'Yayasan Contoh')
        ->set('website_url', 'https://ispo.example.id')
        ->set('brosur', $pdf)
        ->call('kirim')
        ->assertHasErrors(['brosur'])
        ->assertDispatched('sth-form-invalid', form: 'usul')
        ->assertSet('sent', false);

    expect(Competition::query()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0);
});

it('refuses an unsafe link in the Action with the matching sentence and stores nothing', function (string $url, string $sentence): void {
    try {
        app(SubmitCompetition::class)->handle(F::user($this->nadia), ['name' => 'ISPO 2027', 'website_url' => $url]);
        $this->fail('The link was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['website_url' => [$sentence]]);
    }

    expect(Competition::query()->count())->toBe(0);
})->with([
    'http' => ['http://ispo.example.id', ExternalUrl::HTTP],
    'ip address' => ['https://127.0.0.1/admin', ExternalUrl::IP],
    'localhost' => ['https://localhost/', ExternalUrl::IP],
    'credentials' => ['https://user:pass@ispo.example.id', ExternalUrl::CREDENTIALS],
    'odd port' => ['https://ispo.example.id:8081/', ExternalUrl::PORT],
    'javascript' => ['javascript:alert(1)', ExternalUrl::NO_HTTPS],
    'space inside, spaces around' => ['  https://ispo.example.id/info lomba  ', ExternalUrl::SPACE],
]);

it('stores a link trimmed when the Action is called with spaces around it, and none for a blank or non-text value', function (): void {
    $user = F::user($this->nadia);
    $submit = app(SubmitCompetition::class);

    expect($submit->handle($user, ['name' => 'Lomba contoh 1', 'website_url' => " https://x.example/a \n"])->fresh()->website_url)->toBe('https://x.example/a')
        ->and($submit->handle($user, ['name' => 'Lomba contoh 2', 'website_url' => "  \u{00A0} "])->fresh()->website_url)->toBeNull()
        ->and($submit->handle($user, ['name' => 'Lomba contoh 3', 'website_url' => ['https://x.example/a']])->fresh()->website_url)->toBeNull();
});

it('keeps the sheet and the Action closed to an account that may not send a lomba', function (): void {
    // Only siswa and Admin hold competition.create (D-145). Closed: no role, cannot log in.
    $noRole = User::factory()->create()->fresh();
    $disabled = F::user(F::student('Siswa Nonaktif', 'X-1', ['status' => UserStatus::Disabled]));
    $archived = F::user(F::student('Siswa Alumni', 'XII-1', ['status' => UserStatus::Archived]));

    foreach ([$noRole, $disabled, $archived] as $user) {
        Livewire::actingAs($user)->test(UsulanForm::class)->assertForbidden();

        expect(fn () => app(SubmitCompetition::class)->handle($user, ['name' => 'Lomba A', 'website_url' => 'https://x.example/a']))
            ->toThrow(AuthorizationException::class);
    }

    expect(Competition::query()->count())->toBe(0);
});
