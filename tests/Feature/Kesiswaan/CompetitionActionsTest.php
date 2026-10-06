<?php

declare(strict_types=1);

use App\Actions\Competitions\CloseCompetition;
use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Actions\Competitions\VerifyCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\FilePurpose;
use App\Enums\Level;
use App\Enums\RoleName;
use App\Models\Competition;
use App\Notifications\Competitions\CompetitionPublished;
use App\Notifications\Competitions\CompetitionRejected;
use App\Rules\ExternalUrl;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // F::form() has fixed dates (deadline 20 Oct 2026). Since M2-B publishing asks for a confirmation once a
    // date has passed (LombaDatesTest), so "today" is fixed too and these tests read the same on every run.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
    F::boot();
    Notification::fake();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

it('creates a full catalog entry that still waits in the verification queue (D-27)', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->kesiswaan, F::form(), F::pdf());

    expect($competition->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and($competition->submitted_by)->toBe($this->kesiswaan->getKey())
        ->and($competition->verified_by)->toBeNull()
        ->and($competition->level)->toBe(Level::Nasional)
        ->and($competition->max_team_size)->toBe(3)
        ->and($competition->registration_closes_at->format('Y-m-d H:i:s'))->toBe('2026-10-20 23:59:59')
        ->and($competition->juknisFile->purpose)->toBe(FilePurpose::Juknis)
        ->and($competition->juknisFile->disk)->toBe('local')
        ->and(Competition::query()->pendingVerification()->count())->toBe(1);

    Storage::disk('local')->assertExists($competition->juknisFile->path);
    expect(Activity::query()->where('event', 'competition.created')->where('causer_id', $this->kesiswaan->getKey())->exists())->toBeTrue();
});

it('lets Admin use the full form too, but not guru, siswa or humas', function (): void {
    $admin = F::staff(RoleName::Admin, 'Operator STH');
    expect(app(CreateCompetition::class)->handle($admin, F::form())->status)->toBe(CompetitionStatus::MenungguVerifikasi);

    foreach ([F::staff(RoleName::Guru, 'Pak Hendra Kusuma'), F::staff(RoleName::Humas, 'Mas Galih'), F::student()->user] as $user) {
        expect(fn () => app(CreateCompetition::class)->handle($user, F::form()))->toThrow(AuthorizationException::class);
    }
});

it('validates https links, dates and the default mentor', function (): void {
    $create = app(CreateCompetition::class);

    expect(fn () => $create->handle($this->kesiswaan, F::form(['website_url' => 'http://example.org'])))->toThrow(ValidationException::class)
        ->and(fn () => $create->handle($this->kesiswaan, F::form(['registration_url' => 'javascript:alert(1)'])))->toThrow(ValidationException::class)
        ->and(fn () => $create->handle($this->kesiswaan, F::form(['registration_closes_at' => '2026-09-01'])))->toThrow(ValidationException::class)
        ->and(fn () => $create->handle($this->kesiswaan, F::form(['final_on' => '2026-11-01'])))->toThrow(ValidationException::class)
        ->and(fn () => $create->handle($this->kesiswaan, F::form(['default_mentor_id' => $this->kesiswaan->getKey()])))->toThrow(ValidationException::class)
        ->and(fn () => $create->handle($this->kesiswaan, F::form(['level' => 'kecamatan'])))->toThrow(ValidationException::class);

    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    expect($create->handle($this->kesiswaan, F::form(['default_mentor_id' => $guru->getKey()]))->default_mentor_id)->toBe($guru->getKey());
});

it('validates website and registration links with ExternalUrl on create and update (SECURITY §3m-1)', function (string $field, string $url): void {
    $create = app(CreateCompetition::class);

    try {
        $create->handle($this->kesiswaan, F::form([$field => $url]));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors()[$field][0] ?? null)->toBe(ExternalUrl::MESSAGE);
    }

    $competition = $create->handle($this->kesiswaan, F::form());
    expect(fn () => app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, F::form([$field => $url])))->toThrow(ValidationException::class)
        ->and($competition->fresh()->{$field})->toBe(F::form()[$field]);
})->with([
    'ip literal' => ['website_url', 'https://127.0.0.1/lomba'],
    'credentials' => ['registration_url', 'https://user:pass@example.org/daftar'],
    'odd port' => ['website_url', 'https://example.org:8443/'],
    'localhost' => ['registration_url', 'https://localhost/daftar'],
]);

it('refuses to publish a usulan until the weight and date fields are complete', function (): void {
    $siswa = F::student('Raka Pradipta', 'XII-2')->user;
    $usulan = Competition::factory()->proposal()->create(['name' => 'ISPO 2027', 'bidang' => 'riset', 'submitted_by' => $siswa->getKey()]);

    try {
        app(VerifyCompetition::class)->handle($this->kesiswaan, $usulan, CompetitionStatus::Dipublikasikan);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors()['decision'][0])->toContain('Kategori bobot')->toContain('Tingkat')->toContain('Tanggal final');
    }

    expect($usulan->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $usulan, F::form(['name' => 'ISPO 2027', 'level' => 'internasional']));
    app(VerifyCompetition::class)->handle($this->kesiswaan, $usulan->fresh(), CompetitionStatus::Dipublikasikan);

    $usulan->refresh();
    expect($usulan->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($usulan->verified_by)->toBe($this->kesiswaan->getKey())
        ->and($usulan->verified_at)->not->toBeNull()
        ->and($usulan->submitted_by)->toBe($siswa->getKey());

    Notification::assertSentTo($siswa, CompetitionPublished::class, fn (CompetitionPublished $n): bool => $n->kind === 'competition.published');
    expect(Activity::query()->where('event', 'competition.published')->exists())->toBeTrue();
});

it('publishes Kesiswaan own entry only through the queue and does not notify the verifier', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->kesiswaan, F::form());

    app(VerifyCompetition::class)->handle($this->kesiswaan, $competition, CompetitionStatus::Dipublikasikan);

    expect($competition->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan);
    Notification::assertNothingSent();
});

it('rejects with a required note and notifies the submitter', function (): void {
    $guru = F::staff(RoleName::Guru, 'Bu Retno Wulandari');
    $competition = Competition::factory()->proposal()->create(['submitted_by' => $guru->getKey()]);

    expect(fn () => app(VerifyCompetition::class)->handle($this->kesiswaan, $competition, CompetitionStatus::Ditolak, '  '))
        ->toThrow(ValidationException::class);

    app(VerifyCompetition::class)->handle($this->kesiswaan, $competition, CompetitionStatus::Ditolak, 'Lomba sudah lewat.');

    $competition->refresh();
    expect($competition->status)->toBe(CompetitionStatus::Ditolak)
        ->and($competition->verification_note)->toBe('Lomba sudah lewat.')
        ->and($competition->verified_by)->toBe($this->kesiswaan->getKey());

    Notification::assertSentTo($guru, CompetitionRejected::class);
});

it('denies verification to roles without competition.verify and to decided entries', function (): void {
    $competition = Competition::factory()->create(['submitted_by' => $this->kesiswaan->getKey()]);

    foreach ([RoleName::Guru, RoleName::Humas, RoleName::KepalaSekolah, RoleName::TataUsaha] as $role) {
        expect(fn () => app(VerifyCompetition::class)->handle(F::staff($role, 'Staf '.$role->value), $competition, CompetitionStatus::Dipublikasikan))
            ->toThrow(AuthorizationException::class);
    }

    app(VerifyCompetition::class)->handle($this->kesiswaan, $competition, CompetitionStatus::Dipublikasikan);

    expect(fn () => app(VerifyCompetition::class)->handle($this->kesiswaan, $competition->fresh(), CompetitionStatus::Ditolak, 'Ulang'))
        ->toThrow(AuthorizationException::class);
});

it('audits weight field changes and keeps a published lomba complete', function (): void {
    $competition = F::competition();

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, F::form(['level' => 'provinsi', 'weight_category' => 'B']));

    $log = Activity::query()->where('event', 'competition.weights_changed')->firstOrFail();
    expect($log->properties['old']['level'])->toBe('nasional')
        ->and($log->properties['attributes']['level'])->toBe('provinsi')
        ->and($log->properties['attributes']['weight_category'])->toBe('B')
        ->and($competition->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan);

    expect(fn () => app(UpdateCompetition::class)->handle($this->kesiswaan, $competition->fresh(), F::form(['level' => null])))
        ->toThrow(ValidationException::class);
    expect($competition->fresh()->level)->toBe(Level::Provinsi);
});

it('closes a published lomba and refuses to close a queued one', function (): void {
    $published = F::competition();
    $queued = Competition::factory()->create();

    app(CloseCompetition::class)->handle($this->kesiswaan, $published);
    expect($published->fresh()->status)->toBe(CompetitionStatus::Ditutup)
        ->and(Activity::query()->where('event', 'competition.closed')->exists())->toBeTrue();

    expect(fn () => app(CloseCompetition::class)->handle($this->kesiswaan, $queued))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CloseCompetition::class)->handle(F::staff(RoleName::Guru, 'Pak Agus'), $published->fresh()))->toThrow(AuthorizationException::class);
});

it('never lets the form change status or verifier fields', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->kesiswaan, F::form([
        'status' => 'dipublikasikan',
        'verified_by' => $this->kesiswaan->getKey(),
        'submitted_by' => 999,
    ]));

    expect($competition->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and($competition->fresh()->verified_by)->toBeNull()
        ->and($competition->fresh()->submitted_by)->toBe($this->kesiswaan->getKey());
});
