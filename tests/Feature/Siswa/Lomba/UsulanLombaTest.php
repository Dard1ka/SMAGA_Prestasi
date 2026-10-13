<?php

declare(strict_types=1);

use App\Actions\Competitions\SubmitCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Siswa\Lomba\Usulkan;
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
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| Usulan lomba by a student (SubmitCompetition, D-27, D-145): the whole lomba, the juknis required (D-147), the link
| checked by one rule (ExternalUrl), 5 a day. The page that sends it: UsulkanPageTest.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->withoutVite();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Staf Kesiswaan');
    $this->admin = F::staff(RoleName::Admin, 'Operator');
    $this->nadia = F::student();
});

it('stores a student proposal as menunggu_verifikasi and tells the verifiers (D-27)', function (): void {
    $competition = app(SubmitCompetition::class)->handle(F::user($this->nadia), F::usulan([
        'name' => 'ISPO 2027',
        'organizer_name' => 'Yayasan Contoh',
        'website_url' => 'https://ispo.example.id/info',
        'description' => 'Pendaftaran Januari.',
    ]), K::pdf());

    $fresh = $competition->fresh();
    expect($fresh->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and((int) $fresh->submitted_by)->toBe(F::user($this->nadia)->id)
        ->and($fresh->verified_by)->toBeNull()
        ->and($fresh->description)->toBe('Pendaftaran Januari.')
        ->and(Competition::query()->published()->count())->toBe(0);

    Notification::assertSentTo([$this->kesiswaan, $this->admin], CompetitionSubmitted::class);
    expect(Activity::query()->where('event', 'competition.submitted')->exists())->toBeTrue();
});

it('refuses a guru usulan (D-145)', function (): void {
    $guru = F::staff(RoleName::Guru);

    expect(fn () => app(SubmitCompetition::class)->handle($guru, F::usulan(['name' => 'Lomba Debat Kota 2027']), K::pdf()))
        ->toThrow(AuthorizationException::class);

    expect(Competition::query()->count())->toBe(0);
});

it('denies users without competition.create or who cannot log in', function (): void {
    $noRole = User::factory()->create()->fresh();
    $disabled = F::user(F::student('Siswa Nonaktif', 'X-1', ['status' => UserStatus::Disabled]));

    expect(fn () => app(SubmitCompetition::class)->handle($noRole, F::usulan(), K::pdf()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SubmitCompetition::class)->handle($disabled, F::usulan(), K::pdf()))->toThrow(AuthorizationException::class);
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
    expect(fn () => app(SubmitCompetition::class)->handle(F::user($this->nadia), F::usulan(['website_url' => 'http://ispo.example.id']), K::pdf()))
        ->toThrow(ValidationException::class);
    expect(Competition::query()->count())->toBe(0)->and(File::query()->count())->toBe(0);
});

it('limits proposals to 5 per day per user', function (): void {
    $user = F::user($this->nadia);
    for ($i = 1; $i <= 5; $i++) {
        app(SubmitCompetition::class)->handle($user, F::usulan(['name' => "Lomba contoh {$i}"]), K::pdf("juknis-{$i}.pdf"));
    }

    expect(fn () => app(SubmitCompetition::class)->handle($user, F::usulan(['name' => 'Lomba contoh 6']), K::pdf()))->toThrow(ValidationException::class);
    expect(Competition::query()->count())->toBe(5);
});

it('keeps the brochure private on the local disk with a random path', function (): void {
    $pdf = UploadedFile::fake()->createWithContent('Brosur ISPO.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF");

    $competition = app(SubmitCompetition::class)->handle(F::user($this->nadia), F::usulan(['name' => 'ISPO 2027']), $pdf);

    $file = File::query()->findOrFail($competition->fresh()->juknis_file_id);
    expect($file->purpose)->toBe(FilePurpose::Juknis)
        ->and($file->disk)->toBe('local')
        ->and($file->path)->toMatch('~^lampiran/\d{4}/\d{2}/[0-9a-z]{26}\.pdf$~')
        ->and($file->original_name)->toBe('Brosur ISPO.pdf')
        ->and((int) $file->owner_user_id)->toBe(F::user($this->nadia)->id);
    Storage::disk('local')->assertExists($file->path);
});

it('stores the poster as the cover, privately', function (): void {
    $competition = app(SubmitCompetition::class)->handle(F::user($this->nadia), F::usulan(), K::pdf(), UploadedFile::fake()->image('poster.jpg', 600, 800));

    expect($competition->fresh()->coverFile->purpose)->toBe(FilePurpose::Cover)
        ->and($competition->fresh()->coverFile->disk)->toBe('local');
});

it('refuses a PDF with active content and leaves nothing behind', function (): void {
    $pdf = UploadedFile::fake()->createWithContent('brosur.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction 2 0 R >> endobj\n%%EOF");

    expect(fn () => app(SubmitCompetition::class)->handle(F::user($this->nadia), F::usulan(), $pdf))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('juknis'));
    expect(Competition::query()->count())->toBe(0)->and(File::query()->count())->toBe(0);
});

it('takes back the juknis when the poster is refused', function (): void {
    $notImage = UploadedFile::fake()->createWithContent('poster.jpg', '<?php echo 1;');

    expect(fn () => app(SubmitCompetition::class)->handle(F::user($this->nadia), F::usulan(), K::pdf(), $notImage))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('cover'));
    expect(Competition::query()->count())->toBe(0)->and(File::query()->count())->toBe(0);
});

it('refuses an unsafe link in the Action with the matching sentence and stores nothing', function (string $url, string $sentence): void {
    try {
        app(SubmitCompetition::class)->handle(F::user($this->nadia), F::usulan(['website_url' => $url]), K::pdf());
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

it('stores a link trimmed when the Action is called with spaces around it, and asks for one when it is blank', function (): void {
    $user = F::user($this->nadia);
    $submit = app(SubmitCompetition::class);

    expect($submit->handle($user, F::usulan(['name' => 'Lomba contoh 1', 'website_url' => " https://x.example/a \n", 'registration_url' => "  \u{00A0} "]), K::pdf())->fresh())
        ->website_url->toBe('https://x.example/a')
        ->registration_url->toBeNull();

    expect(fn () => $submit->handle($user, F::usulan(['name' => 'Lomba contoh 2', 'website_url' => "  \u{00A0} "]), K::pdf()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toBe(['website_url' => ['Wajib diisi.']]))
        ->and(fn () => $submit->handle($user, F::usulan(['name' => 'Lomba contoh 3', 'website_url' => ['https://x.example/a']]), K::pdf()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('website_url'));
});

it('keeps the Action closed to an account that may not send a lomba', function (): void {
    // Only siswa and Admin hold competition.create (D-145). Closed: no role, cannot log in.
    $noRole = User::factory()->create()->fresh();
    $disabled = F::user(F::student('Siswa Nonaktif', 'X-1', ['status' => UserStatus::Disabled]));
    $archived = F::user(F::student('Siswa Alumni', 'XII-1', ['status' => UserStatus::Archived]));

    foreach ([$noRole, $disabled, $archived] as $user) {
        expect(fn () => app(SubmitCompetition::class)->handle($user, F::usulan(['name' => 'Lomba A']), K::pdf()))
            ->toThrow(AuthorizationException::class);
    }

    expect(Competition::query()->count())->toBe(0);
});

// The page Siswa › Usulkan lomba (ported from the old sheet; the lead's mail test of 2026-10-09: a link was checked as
// typed and every refusal had one sentence) --------------------------------------------------------------------------

/** The page filled with a complete usulan (F::usulan()) and a juknis, as the student would send it. */
function usulkanFilled(User $user, array $overrides = []): Testable
{
    return Livewire::actingAs($user)->test(Usulkan::class)
        ->set(K::fields(F::usulan($overrides)))
        ->set('form.juknis', UploadedFile::fake()->image('poster.jpg', 600, 800));
}

it('accepts a link pasted with spaces or a line break around it and stores it clean', function (string $typed): void {
    usulkanFilled(F::user($this->nadia), [
        'name' => '  ISPO 2027  ',
        'organizer_name' => ' Yayasan Contoh ',
        'website_url' => $typed,
        'description' => "  Pendaftaran Januari.\n",
    ])->call('simpan')->assertHasNoErrors();

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
    Livewire::actingAs(F::user($this->nadia))->test(Usulkan::class)
        ->set(K::fields(F::usulan(['website_url' => "  https://ispo.example.id/info \n"])))
        ->call('simpan')
        ->assertHasErrors(['form.juknis'])
        ->assertHasNoErrors('form.website_url')
        ->assertSet('form.website_url', 'https://ispo.example.id/info');

    expect(Competition::query()->count())->toBe(0);
});

it('says what is wrong with the link on the page and brings the error into view', function (string $typed, string $sentence): void {
    $files = File::query()->count();

    usulkanFilled(F::user($this->nadia), ['website_url' => $typed])
        ->call('simpan')
        ->assertHasErrors(['form.website_url'])
        ->assertSee($sentence)
        ->assertDispatched('sth-form-invalid', form: 'usulkan')
        ->assertSet('competitionId', null);

    expect(Competition::query()->count())->toBe(0)
        ->and(File::query()->count())->toBe($files);
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
    Livewire::actingAs(F::user($this->nadia))->test(Usulkan::class)
        ->assertSee('Link resmi')
        ->assertSee('Salin dari kolom alamat browser, diawali https://. Kesiswaan memeriksa link ini dulu.')
        ->assertSeeHtml('spellcheck="false"')
        ->assertSeeHtml('autocapitalize="off"')
        ->assertSeeHtml('novalidate');
});

it('shows a web address in the name together with the other field errors', function (): void {
    usulkanFilled(F::user($this->nadia), ['name' => 'Daftar di www.lomba-fiktif.example', 'website_url' => 'http://ispo.example.id', 'bidang' => null])
        ->call('simpan')
        ->assertHasErrors(['form.name', 'form.website_url', 'form.bidang'])
        ->assertSee(NoWebAddress::MESSAGE)
        ->assertSee(ExternalUrl::HTTP)
        ->assertDispatched('sth-form-invalid', form: 'usulkan');

    expect(Competition::query()->count())->toBe(0);
});

it('keeps the hourly limit untouched when the page refuses a file, and stops after ten sends an hour', function (): void {
    $user = F::user($this->nadia);

    usulkanFilled($user)
        ->set('form.juknis', UploadedFile::fake()->create('juknis.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
        ->call('simpan')
        ->assertHasErrors(['form.juknis']);

    expect(RateLimiter::attempts('usulan:'.$user->id))->toBe(0);

    for ($i = 0; $i < 10; $i++) {
        RateLimiter::hit('usulan:'.$user->id, 3600);
    }

    usulkanFilled($user)->call('simpan')
        ->assertHasErrors(['form.name'])
        ->assertSee('Terlalu sering. Coba lagi beberapa menit lagi.');

    expect(Competition::query()->count())->toBe(0);
});

it('brings a juknis the Action refuses into view as an error too', function (): void {
    // Passes the page's own file rules (a PDF by name and content), refused by StoreUpload (active content).
    $pdf = UploadedFile::fake()->createWithContent('brosur.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog /OpenAction 2 0 R >> endobj\n%%EOF");

    usulkanFilled(F::user($this->nadia))
        ->set('form.juknis', $pdf)
        ->call('simpan')
        ->assertHasErrors(['form.juknis'])
        ->assertDispatched('sth-form-invalid', form: 'usulkan')
        ->assertSet('competitionId', null);

    expect(Competition::query()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0);
});

it('keeps the page closed to an account that may not send a lomba', function (): void {
    $noRole = User::factory()->create()->fresh();
    $disabled = F::user(F::student('Siswa Nonaktif', 'X-1', ['status' => UserStatus::Disabled]));
    $archived = F::user(F::student('Siswa Alumni', 'XII-1', ['status' => UserStatus::Archived]));

    foreach ([$noRole, $disabled, $archived] as $user) {
        Livewire::actingAs($user)->test(Usulkan::class)->assertForbidden();
    }
});
