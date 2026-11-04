<?php

declare(strict_types=1);

use App\Actions\Competitions\VerifyCompetition;
use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\FilePurpose;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Enums\WeightCategory;
use App\Livewire\Siswa\Lomba\Katalog;
use App\Models\Competition;
use App\Models\File;
use App\Models\Registration;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ClassroomSeeder;
use Database\Seeders\LombaContohSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\TalentSeeder;
use Database\Seeders\WeightSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Siswa\Lomba\LombaFixtures;

/*
 | smaga_server (SV4, lead 2026-11-04): LombaContohSeeder adds ONE published sample lomba for showing the whole flow on
 | the school's server. It is stored as "Tambah lomba" + publish would store it, with a juknis on the private disk, and a
 | second run changes nothing. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, ClassroomSeeder::class, TalentSeeder::class, WeightSeeder::class, SettingSeeder::class]);
    Notification::fake();
    $this->withoutVite();
});

function lcsAdmin(string $name = 'Admin Operator STH'): User
{
    $admin = AuthTestHelpers::staff(RoleName::Admin, ['name' => $name, 'email' => str($name)->slug().'@example.test']);
    $admin->forceFill(['status' => UserStatus::Active])->save();

    return $admin->fresh();
}

/** The page content of a dompdf PDF: every stream, inflated (as DemoDocumentsTest reads it). */
function lcsPdfText(string $bytes): string
{
    preg_match_all('~stream\n(.*?)\nendstream~s', $bytes, $matches);

    return implode("\n", array_map(fn (string $stream): string => (string) @gzuncompress($stream), $matches[1]));
}

it('creates one published sample lomba, as "Tambah lomba" and the publication store it', function (): void {
    $admin = lcsAdmin();
    $today = CarbonImmutable::today();

    $this->artisan('db:seed', ['--class' => LombaContohSeeder::class])
        ->expectsOutputToContain('Lomba contoh dibuat dan dipublikasikan')
        ->assertSuccessful();

    $competition = Competition::query()->sole();

    expect($competition->name)->toBe(LombaContohSeeder::NAME)
        ->and($competition->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($competition->organizer_name)->toBe('Yayasan Cendekia Muda (contoh)')
        ->and($competition->organizer_type)->toBe(OrganizerType::YayasanLembaga)
        ->and($competition->organizer_type_other)->toBeNull()
        // OrganizerWeight (D-146): Yayasan/lembaga without Puspresnas = D · Non-pemerintah.
        ->and($competition->weight_category)->toBe(WeightCategory::D)
        ->and($competition->event_status)->toBe(EventStatus::NonPemerintah)
        ->and($competition->bidang)->toBe(Bidang::Riset)
        ->and($competition->level)->toBe(Level::Provinsi)
        ->and($competition->participation)->toBe(Participation::Individu)
        ->and($competition->max_team_size)->toBeNull()
        ->and($competition->mode)->toBe(EventMode::Daring)
        ->and($competition->location)->toBe('Daring')
        ->and($competition->description)->toContain('Lomba contoh')
        ->and($competition->registration_opens_at->toDateTimeString())->toBe($today->toDateString().' 00:00:00')
        ->and($competition->registration_closes_at->toDateTimeString())->toBe($today->addDays(30)->toDateString().' 23:59:59')
        ->and($competition->starts_on->toDateString())->toBe($today->addDays(45)->toDateString())
        ->and($competition->final_on->toDateString())->toBe($today->addDays(45)->toDateString())
        ->and($competition->submitted_by)->toBe($admin->getKey())
        ->and($competition->verified_by)->toBe($admin->getKey())
        ->and($competition->verified_at)->not->toBeNull()
        ->and($competition->cover_file_id)->toBeNull()
        ->and($competition->default_mentor_id)->toBeNull()
        ->and(VerifyCompetition::missingFields($competition))->toBe([])
        ->and($competition->isOpenForRegistration())->toBeTrue();

    // The juknis: an upload on the private disk (never under demo/, which would switch on "Data contoh"), readable.
    $juknis = File::query()->findOrFail($competition->juknis_file_id);
    $bytes = (string) Storage::disk('local')->get($juknis->path);

    expect($juknis->purpose)->toBe(FilePurpose::Juknis)
        ->and($juknis->disk)->toBe('local')
        ->and($juknis->path)->toStartWith('lampiran/')
        ->and($juknis->mime)->toBe('application/pdf')
        ->and($juknis->original_name)->toBe(LombaContohSeeder::JUKNIS_NAME)
        ->and($juknis->owner_user_id)->toBe($admin->getKey())
        ->and($bytes)->toStartWith('%PDF')
        ->and(hash('sha256', $bytes))->toBe($juknis->sha256)
        ->and(strlen($bytes))->toBe($juknis->size)
        ->and(lcsPdfText($bytes))->toContain('CONTOH')
        ->and(File::query()->where('path', 'like', 'demo/%')->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    // Audited like CreateCompetition and VerifyCompetition; nobody is told.
    $events = Activity::query()->whereIn('event', ['competition.created', 'competition.published'])->orderBy('id')->get();

    expect($events->pluck('event')->all())->toBe(['competition.created', 'competition.published'])
        ->and($events->every(fn (Activity $row): bool => (int) $row->causer_id === $admin->getKey()
            && $row->subject_id === $competition->getKey()))->toBeTrue()
        ->and($events->last()->properties['weight_override'])->toBeNull()
        ->and($events->last()->properties['past_dates'])->toBe([]);
    Notification::assertNothingSent();
});

it('shows the sample lomba to a student in the catalog, open for registration', function (): void {
    lcsAdmin();
    $this->seed(LombaContohSeeder::class);

    $competition = Competition::query()->sole();
    $student = LombaFixtures::student();
    $user = LombaFixtures::user($student);

    expect($user->can('view', $competition))->toBeTrue()
        ->and($user->can('create', [Registration::class, $competition]))->toBeTrue()
        ->and(Competition::query()->openForRegistration()->pluck('id')->all())->toBe([$competition->getKey()])
        ->and($user->can('view', File::query()->findOrFail($competition->juknis_file_id)))->toBeTrue();

    $this->actingAs($user);
    Livewire::test(Katalog::class)->assertSee(LombaContohSeeder::NAME);
    Livewire::test(Katalog::class)->set('status', 'dibuka')->assertSee(LombaContohSeeder::NAME);
});

it('changes nothing on a second run', function (): void {
    lcsAdmin();
    $this->seed(LombaContohSeeder::class);

    $before = [
        'competition' => Competition::query()->sole()->only(['id', 'status', 'registration_closes_at', 'starts_on', 'juknis_file_id', 'updated_at']),
        'files' => File::query()->count(),
        'activity' => Activity::query()->count(),
        'stored' => count(Storage::disk('local')->allFiles()),
    ];

    $this->travel(3)->days();

    $this->artisan('db:seed', ['--class' => LombaContohSeeder::class])
        ->expectsOutputToContain('Lomba contoh sudah ada')
        ->assertSuccessful();

    expect(Competition::query()->count())->toBe(1)
        ->and(Competition::query()->sole()->only(['id', 'status', 'registration_closes_at', 'starts_on', 'juknis_file_id', 'updated_at']))->toEqual($before['competition'])
        ->and(File::query()->count())->toBe($before['files'])
        ->and(Activity::query()->count())->toBe($before['activity'])
        ->and(count(Storage::disk('local')->allFiles()))->toBe($before['stored']);
});

it('takes the first active Admin by id as submitter and verifier', function (): void {
    $disabled = lcsAdmin('Admin Nonaktif');
    $disabled->forceFill(['status' => UserStatus::Disabled])->save();
    $first = lcsAdmin('Admin Pertama');
    lcsAdmin('Admin Kedua');

    $this->seed(LombaContohSeeder::class);

    expect(Competition::query()->sole()->submitted_by)->toBe($first->getKey())
        ->and(Competition::query()->sole()->verified_by)->toBe($first->getKey());
});

it('writes nothing without an active Admin', function (): void {
    AuthTestHelpers::staff(RoleName::Kesiswaan, ['name' => 'Ibu Dwi Rahayu', 'email' => 'dwi.kesiswaan@example.test']);
    lcsAdmin('Admin Nonaktif')->forceFill(['status' => UserStatus::Disabled])->save();
    $activity = Activity::query()->count();

    $this->artisan('db:seed', ['--class' => LombaContohSeeder::class])
        ->expectsOutputToContain('belum ada akun Admin yang aktif')
        ->assertSuccessful();

    expect(Competition::withTrashed()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Activity::query()->count())->toBe($activity);
});

it('writes nothing in production', function (): void {
    lcsAdmin();
    app()['env'] = 'production';

    try {
        // Called directly: `db:seed` itself would stop at its production question before the seeder runs.
        app(LombaContohSeeder::class)->run();
    } finally {
        app()['env'] = 'testing';
    }

    expect(Competition::withTrashed()->count())->toBe(0)
        ->and(File::query()->count())->toBe(0);
});
