<?php

declare(strict_types=1);

use App\Actions\Competitions\CompetitionDates;
use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\SubmitCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\EventStatus;
use App\Enums\FilePurpose;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Models\Competition;
use App\Models\File;
use App\Notifications\Competitions\CompetitionSubmitted;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as K;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| D-145, D-146 (FS1 Paket B): a student proposes the whole lomba. SubmitCompetition validates every field Kesiswaan
| needs, requires the juknis, keeps D-98, computes kategori bobot and status ajang from the jenis penyelenggara (never
| from the input) and stores the lomba in menunggu_verifikasi. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    Notification::fake();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Staf Kesiswaan');
    $this->nadiaUser = F::user(F::student());
});

it('stores a full student lomba in the queue with the computed weight', function (): void {
    $c = app(SubmitCompetition::class)->handle($this->nadiaUser, F::usulan(['organizer_type' => 'pemerintah_daerah']), K::pdf());

    $c->refresh();
    expect($c->status)->toBe(CompetitionStatus::MenungguVerifikasi)->and($c->submitted_by)->toBe($this->nadiaUser->getKey())
        ->and($c->weight_category)->toBe(WeightCategory::B)->and($c->event_status)->toBe(EventStatus::PemerintahNonPuspresnas)
        ->and($c->juknisFile->purpose)->toBe(FilePurpose::Juknis)
        ->and($c->organizer_type)->toBe(OrganizerType::PemerintahDaerah)
        ->and($c->registration_closes_at->format('H:i:s'))->toBe('23:59:59')
        ->and($c->starts_on->toDateString())->toBe($c->final_on->toDateString())
        ->and($c->verified_by)->toBeNull();

    Notification::assertSentTo($this->kesiswaan, CompetitionSubmitted::class);
    expect(Activity::query()->where('event', 'competition.submitted')->sole()->properties->all())
        ->toMatchArray(['has_brochure' => true, 'weight_category' => 'B', 'event_status' => 'pemerintah_non_puspresnas']);
});

it('ignores a weight smuggled in by the student', function (): void {
    $c = app(SubmitCompetition::class)->handle($this->nadiaUser, F::usulan(['weight_category' => 'A', 'event_status' => 'puspresnas', 'default_mentor_id' => 5]), K::pdf());

    expect($c->fresh()->weight_category)->toBe(WeightCategory::C)->and($c->fresh()->event_status)->toBe(EventStatus::NonPemerintah)
        ->and($c->fresh()->default_mentor_id)->toBeNull();
});

it('takes Puspresnas from the tick', function (): void {
    $c = app(SubmitCompetition::class)->handle($this->nadiaUser, F::usulan(['organizer_type' => 'yayasan_lembaga', 'puspresnas' => true]), K::pdf());

    expect($c->fresh()->weight_category)->toBe(WeightCategory::A)->and($c->fresh()->event_status)->toBe(EventStatus::Puspresnas);
});

it('requires the juknis and the fields Kesiswaan needs', function (): void {
    expect(fn () => app(SubmitCompetition::class)->handle($this->nadiaUser, ['name' => 'ISPO 2027']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKeys([
            'juknis', 'bidang', 'level', 'organizer_name', 'organizer_type', 'mode', 'location', 'registration_closes_at',
            'final_on', 'participation', 'website_url',
        ])->and($e->errors()['juknis'])->toBe([CreateCompetition::JUKNIS_MISSING]));

    expect(Competition::query()->count())->toBe(0);
});

it('requires the typed kind for "Lainnya" and the team size for a team', function (): void {
    expect(fn () => app(SubmitCompetition::class)->handle($this->nadiaUser, F::usulan([
        'organizer_type' => 'lainnya', 'organizer_type_other' => '  ', 'participation' => Participation::Tim->value,
    ]), K::pdf()))->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKeys(['organizer_type_other', 'max_team_size']));

    $c = app(SubmitCompetition::class)->handle($this->nadiaUser, F::usulan([
        'organizer_type' => 'lainnya', 'organizer_type_other' => 'Sanggar seni', 'participation' => Participation::Tim->value, 'max_team_size' => 4,
    ]), K::pdf());

    expect($c->fresh()->organizer_type_other)->toBe('Sanggar seni')->and($c->fresh()->max_team_size)->toBe(4)
        ->and($c->fresh()->weight_category)->toBe(WeightCategory::E);
});

it('drops the typed kind when the type is not "Lainnya"', function (): void {
    $c = app(SubmitCompetition::class)->handle($this->nadiaUser, F::usulan(['organizer_type' => 'ptn', 'organizer_type_other' => 'x']), K::pdf());

    expect($c->fresh()->organizer_type_other)->toBeNull();
});

it('keeps D-98: start never before the registration closes', function (): void {
    expect(fn () => app(SubmitCompetition::class)->handle($this->nadiaUser, F::usulan([
        'registration_closes_at' => now()->addDays(20)->format('Y-m-d'),
        'starts_on' => now()->addDays(10)->format('Y-m-d'),
        'final_on' => now()->addDays(30)->format('Y-m-d'),
    ]), K::pdf()))->toThrow(fn (ValidationException $e) => expect($e->errors()['starts_on'])->toBe([CompetitionDates::STARTS_BEFORE_DEADLINE]));

    expect(Competition::query()->count())->toBe(0)->and(File::query()->count())->toBe(0);
});

it('treats a lomba without a start date as a one-day lomba', function (): void {
    $c = app(SubmitCompetition::class)->handle($this->nadiaUser, F::usulan(['starts_on' => null]), K::pdf());

    expect($c->fresh()->starts_on?->toDateString())->toBe($c->fresh()->final_on->toDateString());
});

it('refuses the sixth lomba of the day and leaves no file behind', function (): void {
    Competition::factory()->count(5)->create(['submitted_by' => $this->nadiaUser->getKey()]);
    $before = File::query()->count();

    expect(fn () => app(SubmitCompetition::class)->handle($this->nadiaUser, F::usulan(), K::pdf()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['name'])->toBe(['Sudah 5 kali mengirim hari ini. Coba lagi besok.']));

    expect(File::query()->count())->toBe($before)
        ->and(Competition::query()->count())->toBe(5);
});

it('never lets a guru or Kesiswaan propose a lomba (D-145)', function (RoleName $role): void {
    expect(fn () => app(SubmitCompetition::class)->handle(F::staff($role, 'Staf '.$role->value), F::usulan(), K::pdf()))
        ->toThrow(AuthorizationException::class);
})->with([RoleName::Guru, RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha, RoleName::Humas]);
