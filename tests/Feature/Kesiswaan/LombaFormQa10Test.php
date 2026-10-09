<?php

declare(strict_types=1);

use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Kesiswaan\Lomba\Form;
use App\Models\Competition;
use App\Models\File;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
 * QA10: Kesiswaan › Kelola lomba, the drawer form and its Actions (CreateCompetition, UpdateCompetition).
 * - A cleared date field ('' from the browser) was compared with "now" by the date rules.
 * - A default guru pembimbing whose account left blocked every save and publish of the lomba.
 * - Switching a lomba to individu, or lowering "Maks anggota", while teams were still registered.
 * - Uploads stored before a failing save stayed on the disk (and every retry stored another copy).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-10-09 09:00:00');
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

/** A published, complete lomba (team of at most 5) whose registration deadline has passed. */
function qa10LombaPublished(array $attributes = []): Competition
{
    return F::competition(array_merge([
        'organizer_type' => 'perusahaan_swasta',
        'event_status' => 'non_pemerintah',
        'mode' => 'daring',
        'location' => 'Daring',
        'participation' => Participation::Tim,
        'max_team_size' => 5,
        'registration_opens_at' => '2026-09-01 00:00:00',
        'registration_closes_at' => '2026-10-01 23:59:59',
        'starts_on' => '2026-10-20',
        'final_on' => '2026-10-20',
    ], $attributes));
}

/** The drawer's form values of a lomba (what "Ubah lomba" fills in). @return array<string, mixed> */
function qa10LombaInput(Competition $competition, array $overrides = []): array
{
    $form = [];

    foreach (array_keys(CreateCompetition::rules()) as $field) {
        $value = $competition->getAttribute($field);
        $form[$field] = match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => $value,
        };
    }

    return array_merge($form, $overrides);
}

function qa10LombaTeamRegistration(Competition $competition, RegistrationStatus $status = RegistrationStatus::MenungguKonfirmasi): Registration
{
    return Registration::factory()->create([
        'competition_id' => $competition->getKey(),
        'leader_student_id' => F::student('Raka Pradipta', 'XII-2')->getKey(),
        'type' => RegistrationType::Tim,
        'status' => $status,
    ]);
}

function qa10LombaDisabledGuru(string $name = 'Pak Hendra Kusuma'): User
{
    $guru = F::staff(RoleName::Guru, $name);
    $guru->forceFill(['status' => UserStatus::Disabled])->save();

    return $guru->fresh();
}

/** @return list<string> stored upload bytes on the private disk (Livewire's temporary uploads left out) */
function qa10LombaStoredFiles(): array
{
    return collect(Storage::disk('local')->allFiles())
        ->reject(fn (string $path): bool => str_starts_with($path, 'livewire-tmp'))
        ->values()
        ->all();
}

// Cleared date fields -------------------------------------------------------------------------------------------

it('accepts a cleared "Daftar dibuka" on a lomba whose registration deadline has passed (QA10)', function (): void {
    $competition = qa10LombaPublished();

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, qa10LombaInput($competition, ['registration_opens_at' => '']));

    expect($competition->fresh()->registration_opens_at)->toBeNull()
        ->and($competition->fresh()->registration_closes_at->toDateString())->toBe('2026-10-01');
});

it('accepts a cleared "Tanggal mulai" next to a final date in the past (QA10)', function (): void {
    $competition = Competition::factory()->create(['name' => 'Lomba Esai Fiktif 2026', 'starts_on' => '2026-09-01', 'final_on' => '2026-09-02']);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, qa10LombaInput($competition, ['starts_on' => '']));

    expect($competition->fresh()->starts_on)->toBeNull()
        ->and($competition->fresh()->final_on->toDateString())->toBe('2026-09-02');
});

it('accepts a new lomba for the record with "Daftar dibuka" left empty (QA10)', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->kesiswaan, F::form([
        'registration_opens_at' => '', 'registration_closes_at' => '2026-08-01', 'starts_on' => '2026-08-10', 'final_on' => '2026-08-10',
    ]));

    expect($competition->registration_opens_at)->toBeNull()
        ->and($competition->registration_closes_at->toDateString())->toBe('2026-08-01');
});

it('saves the drawer after "Daftar dibuka" was cleared (QA10)', function (): void {
    $competition = qa10LombaPublished();

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->set('form.registration_opens_at', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan.', tone: 'success');

    expect($competition->fresh()->registration_opens_at)->toBeNull();
});

it('still refuses a deadline before the opening date when both are filled (QA10)', function (): void {
    $competition = qa10LombaPublished();

    expect(fn () => app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, qa10LombaInput($competition, [
        'registration_opens_at' => '2026-10-05', 'registration_closes_at' => '2026-10-01',
    ])))->toThrow(ValidationException::class, 'Batas daftar tidak boleh sebelum tanggal dibuka.');
});

// Default guru pembimbing who is no longer active ----------------------------------------------------------------

it('keeps an unchanged default guru pembimbing who is no longer active (QA10)', function (): void {
    $guru = qa10LombaDisabledGuru();
    $competition = qa10LombaPublished(['default_mentor_id' => $guru->getKey()]);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, qa10LombaInput($competition, ['location' => 'Kota Semarang']));

    expect($competition->fresh()->location)->toBe('Kota Semarang')
        ->and($competition->fresh()->default_mentor_id)->toBe($guru->getKey());
});

it('still refuses to pick a guru who is no longer active (QA10)', function (): void {
    $kept = qa10LombaDisabledGuru();
    $other = qa10LombaDisabledGuru('Bu Retno Wulandari');
    $competition = qa10LombaPublished(['default_mentor_id' => $kept->getKey()]);

    expect(fn () => app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, qa10LombaInput($competition, ['default_mentor_id' => $other->getKey()])))
        ->toThrow(ValidationException::class, 'Pilih guru pembimbing dari daftar.');

    expect(fn () => app(CreateCompetition::class)->handle($this->kesiswaan, F::form(['default_mentor_id' => $kept->getKey()])))
        ->toThrow(ValidationException::class, 'Pilih guru pembimbing dari daftar.');
});

it('publishes a queued lomba whose default guru pembimbing left, and says so in the drawer (QA10)', function (): void {
    $guru = qa10LombaDisabledGuru();
    $competition = Competition::factory()->create([
        ...F::form(['registration_opens_at' => '2026-10-01 00:00:00', 'registration_closes_at' => '2026-10-20 23:59:59']),
        'default_mentor_id' => $guru->getKey(),
    ]);

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->assertSet('formMode', 'verify')
        ->assertSee('Pak Hendra Kusuma (tidak aktif)')
        ->assertSee('Guru pembimbing yang tercatat, Pak Hendra Kusuma, tidak aktif lagi.')
        ->call('saveAndPublish')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Lomba dipublikasikan ke katalog.', tone: 'success');

    expect($competition->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($competition->fresh()->default_mentor_id)->toBe($guru->getKey());
});

// Team rules while teams are registered -------------------------------------------------------------------------

it('refuses switching to individu while a team registration is still running (QA10)', function (): void {
    $competition = qa10LombaPublished();
    qa10LombaTeamRegistration($competition);

    try {
        app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, qa10LombaInput($competition, ['participation' => 'individu']));
        $this->fail('The change to individu was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('participation')
            ->and($e->errors()['participation'][0])->toBe(UpdateCompetition::TEAMS_RUNNING_PARTICIPATION);
    }

    expect($competition->fresh()->participation)->toBe(Participation::Tim)
        ->and($competition->fresh()->max_team_size)->toBe(5);
});

it('refuses a lower "Maks anggota" while a team registration is still running (QA10)', function (): void {
    $competition = qa10LombaPublished();
    qa10LombaTeamRegistration($competition, RegistrationStatus::Berlangsung);

    try {
        app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, qa10LombaInput($competition, ['max_team_size' => 3]));
        $this->fail('The lower team size was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('max_team_size')
            ->and($e->errors()['max_team_size'][0])->toBe(UpdateCompetition::TEAMS_RUNNING_MAX);
    }

    expect($competition->fresh()->max_team_size)->toBe(5);
});

it('allows a higher "Maks anggota" and the team changes once the team registrations are final (QA10)', function (): void {
    $competition = qa10LombaPublished();
    $registration = qa10LombaTeamRegistration($competition);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, qa10LombaInput($competition, ['max_team_size' => 6]));
    expect($competition->fresh()->max_team_size)->toBe(6);

    $registration->forceFill(['status' => RegistrationStatus::Selesai])->save();

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition->fresh(), qa10LombaInput($competition->fresh(), ['max_team_size' => 3]));
    expect($competition->fresh()->max_team_size)->toBe(3);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition->fresh(), qa10LombaInput($competition->fresh(), ['participation' => 'individu']));
    expect($competition->fresh()->participation)->toBe(Participation::Individu)
        ->and($competition->fresh()->max_team_size)->toBeNull();
});

it('shows the running-teams refusal on the "Peserta" field of the drawer (QA10)', function (): void {
    $competition = qa10LombaPublished();
    qa10LombaTeamRegistration($competition);

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->set('form.participation', 'individu')
        ->call('save')
        ->assertHasErrors(['form.participation']);

    expect($competition->fresh()->participation)->toBe(Participation::Tim);
});

// Uploads of a failing save -------------------------------------------------------------------------------------

it('discards the uploaded juknis and cover when the update is refused (QA10)', function (): void {
    $competition = Competition::factory()->create(['name' => 'Lomba Esai Fiktif 2026']);

    expect(fn () => app(UpdateCompetition::class)->handle(
        $this->kesiswaan,
        $competition,
        qa10LombaInput($competition, ['registration_closes_at' => '2026-11-10', 'starts_on' => '2026-11-01', 'final_on' => '2026-11-01']),
        F::pdf(),
        UploadedFile::fake()->image('poster.png', 300, 400),
    ))->toThrow(ValidationException::class);

    expect(File::query()->count())->toBe(0)
        ->and(qa10LombaStoredFiles())->toBe([])
        ->and($competition->fresh()->juknis_file_id)->toBeNull();
});

it('discards the uploaded files when creating the lomba fails (QA10)', function (): void {
    Competition::creating(function (): void {
        throw new RuntimeException('Simulated database failure.');
    });

    expect(fn () => app(CreateCompetition::class)->handle($this->kesiswaan, F::form(), F::pdf(), UploadedFile::fake()->image('poster.png', 300, 400)))
        ->toThrow(RuntimeException::class);

    expect(File::query()->count())->toBe(0)
        ->and(qa10LombaStoredFiles())->toBe([]);
});

it('stores no extra copy of the cover when a refused save is tried again (QA10)', function (): void {
    $competition = Competition::factory()->create([
        'name' => 'Lomba Esai Fiktif 2026', 'registration_closes_at' => '2026-10-25 23:59:59', 'starts_on' => '2026-11-01', 'final_on' => '2026-11-01',
    ]);

    // "Batas daftar" after "Tanggal mulai": refused inside UpdateCompetition, after the upload was stored.
    $component = Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->set('form.registration_closes_at', '2026-11-10')
        ->set('cover', UploadedFile::fake()->image('poster.png', 300, 400));

    $component->call('save')->assertHasErrors(['form.starts_on']);
    $component->call('save')->assertHasErrors(['form.starts_on']);

    expect(File::query()->count())->toBe(0)
        ->and(qa10LombaStoredFiles())->toBe([]);
});
