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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
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

it('lets a guru submit a lomba too, still waiting for Kesiswaan', function (): void {
    $guru = F::staff(RoleName::Guru);

    $competition = app(SubmitCompetition::class)->handle($guru, ['name' => 'Lomba Debat Kota 2027']);

    expect($competition->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi);
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

    Livewire::test(UsulanForm::class)
        ->call('kirim')
        ->assertHasErrors(['name' => 'required'])
        ->set('name', 'ISPO 2027')
        ->set('website_url', 'http://ispo.example.id')
        ->call('kirim')
        ->assertHasErrors(['website_url'])
        ->set('website_url', 'https://ispo.example.id')
        ->set('brosur', UploadedFile::fake()->image('poster.jpg', 1200, 800))
        ->call('kirim')
        ->assertHasNoErrors()
        ->assertSet('sent', true)
        ->assertSee('Usulan terkirim');

    $competition = Competition::query()->sole();
    expect($competition->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and(File::query()->sole()->mime)->toBe('image/jpeg');
});
