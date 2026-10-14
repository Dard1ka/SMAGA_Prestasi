<?php

declare(strict_types=1);

use App\Actions\Competitions\CompetitionDates;
use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Actions\Competitions\VerifyCompetition;
use App\Enums\RoleName;
use App\Models\Competition;
use App\Models\File;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Kesiswaan\LombaChangeFixtures;
use Tests\Feature\Siswa\Lomba\LombaFixtures;

/*
| D-147: every lomba carries its juknis, brosur or undangan. Required on create, kept on update (an old lomba without
| one is refused only when Kesiswaan saves it again), and before publishing. The date fields use the words "Pendaftaran
| ditutup" and "Tanggal selesai". "Today" is fixed at 6 Oct 2026. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
    F::boot();
    Notification::fake();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

it('refuses a new lomba without juknis', function (): void {
    expect(fn () => app(CreateCompetition::class)->handle($this->admin, F::form()))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['juknis'])->toBe(['Upload juknis, brosur, atau undangan.']));

    expect(Competition::query()->count())->toBe(0)
        ->and(CreateCompetition::JUKNIS_MISSING)->toBe('Upload juknis, brosur, atau undangan.');
});

it('names the missing juknis together with the other field errors', function (): void {
    try {
        app(CreateCompetition::class)->handle($this->admin, F::form(['website_url' => 'http://tidak-aman.test']));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['website_url', 'juknis']);
    }
});

it('refuses to re-save an old lomba without juknis and saves it once one is uploaded', function (): void {
    $old = F::competition(['juknis_file_id' => null]);
    $files = File::query()->count();

    expect(fn () => app(UpdateCompetition::class)->handle($this->kesiswaan, $old, LombaChangeFixtures::input($old)))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['juknis'])->toBe([CreateCompetition::JUKNIS_MISSING]));

    expect(File::query()->count())->toBe($files);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $old, LombaChangeFixtures::input($old), F::pdf());
    expect($old->fresh()->juknis_file_id)->not->toBeNull();

    // Kept: a later save without a new upload keeps the stored juknis.
    app(UpdateCompetition::class)->handle($this->kesiswaan, $old->fresh(), LombaChangeFixtures::input($old->fresh(), ['location' => 'Kota Semarang']));
    expect($old->fresh()->location)->toBe('Kota Semarang')->and($old->fresh()->juknis_file_id)->not->toBeNull();
});

it('keeps showing an old published lomba without juknis to students', function (): void {
    $old = F::competition(['juknis_file_id' => null]);
    $this->withoutVite();

    $this->actingAs(LombaFixtures::user(LombaFixtures::student()))->get(route('siswa.lomba.show', $old))->assertOk();
});

it('does not publish a queued lomba without juknis', function (): void {
    $queued = Competition::factory()->create(['juknis_file_id' => null]);

    expect(VerifyCompetition::missingFields($queued))->toHaveKey('juknis_file_id', 'Juknis / brosur / undangan')
        ->and(VerifyCompetition::missingFields(Competition::factory()->create()))->toBe([]);
});

it('names the date fields with the new words', function (): void {
    expect(CompetitionDates::STARTS_BEFORE_DEADLINE)->toBe('Lomba tidak boleh dimulai sebelum pendaftaran ditutup.')
        ->and(CompetitionDates::past('2026-08-01', '2026-08-11', CarbonImmutable::parse('2026-10-12')))
        ->toBe(['registration_closes_at' => 'pendaftaran ditutup 1 Agt 2026', 'final_on' => 'tanggal selesai 11 Agt 2026'])
        ->and(CreateCompetition::messages()['registration_closes_at.after_or_equal'])->toBe('Pendaftaran ditutup tidak boleh sebelum pendaftaran dibuka.')
        ->and(VerifyCompetition::REQUIRED_TO_PUBLISH)->toMatchArray([
            'registration_closes_at' => 'Pendaftaran ditutup',
            'starts_on' => 'Tanggal mulai',
            'final_on' => 'Tanggal selesai',
        ]);
});
