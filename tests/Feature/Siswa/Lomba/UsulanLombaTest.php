<?php

declare(strict_types=1);

use App\Actions\Competitions\SubmitCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Competition;
use App\Models\File;
use App\Models\User;
use App\Notifications\Competitions\CompetitionSubmitted;
use App\Rules\ExternalUrl;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
