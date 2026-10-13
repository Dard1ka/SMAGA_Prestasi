<?php

declare(strict_types=1);

use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\SubmitCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Actions\Competitions\VerifyCompetition;
use App\Actions\Exports\AchievementFilters;
use App\Actions\Exports\ExportAchievements;
use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\EventStatus;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Livewire\Guru\Lomba as GuruLomba;
use App\Livewire\Kesiswaan\DatabasePrestasi;
use App\Livewire\Kesiswaan\Lomba\Form;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Competition;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Kesiswaan\Prestasi\PrestasiFixtures;
use Tests\Feature\Siswa\Lomba\LombaFixtures;

/*
| The lead's request of 2026-10-09 (D-125): "Jenis penyelenggara: Lainnya" said nothing about the organizer. The
| drawer of Kelola lomba now asks what kind it is (competitions.organizer_type_other, at most 60 characters):
| - shown and stored only while the type is "Lainnya"; any other type drops the text, whatever the form still holds;
| - may stay empty while the lomba waits in the queue, required before it is published (and on a published lomba);
| - shown as "Lainnya (Sanggar seni)" where the type is shown: Guru › Lomba, Kesiswaan › Database prestasi, the Excel;
| - never a weight field: points come from tingkat × capaian × kategori bobot only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // F::form() has fixed dates (deadline 20 Oct 2026); "today" is fixed so publishing asks nothing about past dates.
    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    // Only siswa and Admin add a lomba (D-145); Kesiswaan corrects and publishes it.
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

/** F::form() with "Jenis penyelenggara: Lainnya" and the typed kind. @return array<string, mixed> */
function lainnyaForm(?string $other = 'Sanggar seni', array $overrides = []): array
{
    return F::form(array_merge([
        'organizer_name' => 'Sanggar Seni Pelajar Semarang',
        'organizer_type' => OrganizerType::Lainnya->value,
        'organizer_type_other' => $other,
    ], $overrides));
}

/** A published, complete lomba whose organizer is "Lainnya (Sanggar seni)". */
function lainnyaPublished(array $attributes = []): Competition
{
    return F::competition(array_merge([
        'name' => 'Festival Tari Pelajar Fiktif 2026',
        'organizer_name' => 'Sanggar Seni Pelajar Semarang',
        'organizer_type' => OrganizerType::Lainnya,
        'organizer_type_other' => 'Sanggar seni',
        'weight_category' => WeightCategory::E,
        'event_status' => EventStatus::NonPemerintah,
        'mode' => 'luring',
        'location' => 'Kota Semarang',
        'registration_opens_at' => '2026-09-15 00:00:00',
        'registration_closes_at' => '2026-10-20 23:59:59',
        'starts_on' => '2026-11-07',
        'final_on' => '2026-11-07',
    ], $attributes))->fresh();
}

// The label --------------------------------------------------------------------------------------------------------

it('labels the organizer type with the typed kind only for "Lainnya"', function (): void {
    $label = fn (array $attributes): ?string => (new Competition)->forceFill($attributes)->organizerTypeLabel();

    expect($label(['organizer_type' => null, 'organizer_type_other' => null]))->toBeNull()
        ->and($label(['organizer_type' => null, 'organizer_type_other' => 'Sanggar seni']))->toBeNull()
        ->and($label(['organizer_type' => 'ptn', 'organizer_type_other' => null]))->toBe('Perguruan tinggi negeri')
        ->and($label(['organizer_type' => 'ptn', 'organizer_type_other' => 'Sanggar seni']))->toBe('Perguruan tinggi negeri')
        ->and($label(['organizer_type' => 'lainnya', 'organizer_type_other' => null]))->toBe('Lainnya')
        ->and($label(['organizer_type' => 'lainnya', 'organizer_type_other' => '   ']))->toBe('Lainnya')
        ->and($label(['organizer_type' => 'lainnya', 'organizer_type_other' => 'Sanggar seni']))->toBe('Lainnya (Sanggar seni)');
});

// CreateCompetition / UpdateCompetition ------------------------------------------------------------------------------

it('stores the typed kind trimmed when the type is "Lainnya"', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm('  Komunitas film pelajar  '), F::pdf());

    expect($competition->fresh()->organizer_type)->toBe(OrganizerType::Lainnya)
        ->and($competition->fresh()->organizer_type_other)->toBe('Komunitas film pelajar')
        ->and($competition->fresh()->organizerTypeLabel())->toBe('Lainnya (Komunitas film pelajar)');
});

it('drops a typed kind when the type is not "Lainnya"', function (?string $type): void {
    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm('Sanggar seni', ['organizer_type' => $type]), F::pdf());

    expect($competition->fresh()->organizer_type?->value)->toBe($type)
        ->and($competition->fresh()->organizer_type_other)->toBeNull();
})->with([
    'another type' => [OrganizerType::PerusahaanSwasta->value],
    'no type yet' => [null],
]);

it('accepts "Lainnya" without the typed kind for the queue', function (?string $other): void {
    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm($other), F::pdf());

    expect($competition->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and($competition->fresh()->organizer_type)->toBe(OrganizerType::Lainnya)
        ->and($competition->fresh()->organizer_type_other)->toBeNull();
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'null' => [null],
]);

it('accepts "Lainnya" for the queue when the form has no such field at all', function (): void {
    $input = lainnyaForm();
    unset($input['organizer_type_other']);

    expect(app(CreateCompetition::class)->handle($this->admin, $input, F::pdf())->fresh()->organizer_type_other)->toBeNull();
});

it('refuses a typed kind longer than 60 characters or one that is not text, on create and on update', function (mixed $other, ?string $sentence): void {
    try {
        app(CreateCompetition::class)->handle($this->admin, lainnyaForm(overrides: ['organizer_type_other' => $other]), F::pdf());
        $this->fail('The typed kind was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('organizer_type_other');

        if ($sentence !== null) {
            expect($e->errors()['organizer_type_other'])->toBe([$sentence]);
        }
    }

    expect(Competition::query()->count())->toBe(0);

    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm(), F::pdf());

    expect(fn () => app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, lainnyaForm(overrides: ['organizer_type_other' => $other])))
        ->toThrow(ValidationException::class);

    expect($competition->fresh()->organizer_type_other)->toBe('Sanggar seni');
})->with([
    '61 characters' => [str_repeat('a', 61), 'Maksimal 60 karakter.'],
    'array' => [['Sanggar seni'], null],
]);

it('accepts a typed kind of exactly 60 characters', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm(str_repeat('a', 60)), F::pdf());

    expect($competition->fresh()->organizer_type_other)->toBe(str_repeat('a', 60));
});

it('drops the typed kind when the type changes and stores a new one when it comes back', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm(), F::pdf());
    $update = app(UpdateCompetition::class);

    $update->handle($this->kesiswaan, $competition, lainnyaForm('Sanggar seni', ['organizer_type' => OrganizerType::Ptn->value]));

    expect($competition->fresh()->organizer_type)->toBe(OrganizerType::Ptn)
        ->and($competition->fresh()->organizer_type_other)->toBeNull();

    $update->handle($this->kesiswaan, $competition->fresh(), lainnyaForm('Komunitas'));

    expect($competition->fresh()->organizer_type)->toBe(OrganizerType::Lainnya)
        ->and($competition->fresh()->organizer_type_other)->toBe('Komunitas');
});

it('drops the typed kind when an update with another type does not send the field', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm(), F::pdf());

    // F::form() has no organizer_type_other key and the type "Perusahaan swasta".
    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, F::form());

    expect($competition->fresh()->organizer_type)->toBe(OrganizerType::PerusahaanSwasta)
        ->and($competition->fresh()->organizer_type_other)->toBeNull();
});

// Publishing -------------------------------------------------------------------------------------------------------

it('lists the typed kind as missing only for "Lainnya" without it, right after the type', function (): void {
    $complete = Competition::factory()->make(lainnyaForm());

    expect(VerifyCompetition::missingFields($complete))->toBe([])
        ->and(VerifyCompetition::missingFields(Competition::factory()->make(lainnyaForm('  '))))->toBe(['organizer_type_other' => 'Jenis penyelenggara lainnya'])
        ->and(VerifyCompetition::missingFields(Competition::factory()->make(lainnyaForm(null))))->toBe(['organizer_type_other' => 'Jenis penyelenggara lainnya'])
        ->and(VerifyCompetition::missingFields(Competition::factory()->make(F::form())))->toBe([])
        ->and(VerifyCompetition::OTHER_TYPE_LABEL)->toBe('Jenis penyelenggara lainnya');

    // A proposal has no type yet: the type itself is missing, the typed kind is not asked for.
    $proposal = VerifyCompetition::missingFields(Competition::factory()->proposal()->make());

    expect($proposal)->toHaveKey('organizer_type')->not->toHaveKey('organizer_type_other');

    // Form order: Penyelenggara, Jenis penyelenggara, Jenis penyelenggara lainnya, Kategori bobot.
    $keys = array_keys(VerifyCompetition::missingFields(Competition::factory()->make(lainnyaForm(null, ['organizer_name' => null, 'weight_category' => null]))));

    expect($keys)->toBe(['organizer_name', 'organizer_type_other', 'weight_category']);
});

it('does not publish a queued "Lainnya" lomba until the kind is typed', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm(null), F::pdf());

    try {
        app(VerifyCompetition::class)->handle($this->kesiswaan, $competition, CompetitionStatus::Dipublikasikan);
        $this->fail('The lomba was published.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'decision' => ['Lengkapi dulu: Jenis penyelenggara lainnya.'],
            'organizer_type_other' => ['Wajib diisi sebelum tampil di katalog.'],
        ]);
    }

    expect($competition->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition->fresh(), lainnyaForm('Sanggar seni'));
    app(VerifyCompetition::class)->handle($this->kesiswaan, $competition->fresh(), CompetitionStatus::Dipublikasikan);

    expect($competition->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($competition->fresh()->organizer_type_other)->toBe('Sanggar seni');
});

it('keeps a published "Lainnya" lomba complete: the kind cannot be emptied, another type can be chosen', function (): void {
    $competition = lainnyaPublished();
    $input = lainnyaForm('', ['name' => $competition->name]);

    try {
        app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, $input);
        $this->fail('The empty kind was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'status' => ['Lomba yang sudah tampil di katalog harus lengkap: Jenis penyelenggara lainnya.'],
            'organizer_type_other' => ['Wajib diisi sebelum tampil di katalog.'],
        ]);
    }

    expect($competition->fresh()->organizer_type_other)->toBe('Sanggar seni');

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition->fresh(), [...$input, 'organizer_type' => OrganizerType::Ptn->value]);

    expect($competition->fresh()->organizer_type)->toBe(OrganizerType::Ptn)
        ->and($competition->fresh()->organizer_type_other)->toBeNull()
        ->and($competition->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan);
});

// Points -----------------------------------------------------------------------------------------------------------

it('never lets the typed kind touch the points', function (): void {
    $competition = lainnyaPublished(['weight_category' => WeightCategory::D, 'level' => Level::Nasional]);
    $student = F::student('Nadia Putri Anindya', 'XI-4', '225226201');
    [$achievement] = (new PrestasiFixtures)->prestasi([$student], $competition, ResultType::Juara1);
    $before = $achievement->only(['w_level', 'w_result', 'w_organizer', 'points', 'weight_category']);

    $rumus = fn (): array => Livewire::actingAs($this->kesiswaan)->test(Form::class)->call('open', $competition->getKey())->viewData('rumus');
    $rumusBefore = $rumus();

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, [
        ...lainnyaForm('Komunitas tari', ['name' => $competition->name, 'weight_category' => 'D', 'level' => 'nasional']),
    ]);

    expect($competition->fresh()->organizer_type_other)->toBe('Komunitas tari')
        ->and(Activity::query()->where('event', 'competition.weights_changed')->exists())->toBeFalse()
        ->and($rumus())->toBe($rumusBefore)
        ->and($achievement->fresh()->only(['w_level', 'w_result', 'w_organizer', 'points', 'weight_category']))->toEqual($before);
});

// Who may type it ----------------------------------------------------------------------------------------------------

it('keeps the field closed to roles that do not manage lomba', function (RoleName $role): void {
    $user = $role === RoleName::Siswa ? F::student()->user : F::staff($role, 'Staf '.$role->value);
    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm(), F::pdf());

    expect(fn () => app(CreateCompetition::class)->handle($user, lainnyaForm('Komunitas', ['name' => 'Lomba Esai Fiktif 2026'])))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateCompetition::class)->handle($user, $competition, lainnyaForm('Komunitas')))
        ->toThrow(AuthorizationException::class);

    Livewire::actingAs($user)->test(Form::class)->assertForbidden();

    expect(Competition::query()->count())->toBe(1)
        ->and($competition->fresh()->organizer_type_other)->toBe('Sanggar seni');
})->with([RoleName::Siswa, RoleName::Guru, RoleName::Humas, RoleName::TataUsaha, RoleName::KepalaSekolah]);

it('keeps the typed kind of a lomba a student proposes only for "Lainnya" (D-145, D-125)', function (): void {
    $student = F::student()->user;

    $other = app(SubmitCompetition::class)->handle($student, LombaFixtures::usulan([
        'organizer_type' => 'yayasan_lembaga', 'organizer_type_other' => 'Sanggar seni',
    ]), F::pdf());
    $lainnya = app(SubmitCompetition::class)->handle($student, LombaFixtures::usulan([
        'name' => 'Festival Tari Pelajar Fiktif 2027', 'organizer_type' => 'lainnya', 'organizer_type_other' => '  Sanggar seni ',
    ]), F::pdf());

    expect($other->fresh()->organizer_type)->toBe(OrganizerType::YayasanLembaga)
        ->and($other->fresh()->organizer_type_other)->toBeNull()
        ->and($lainnya->fresh()->organizerTypeLabel())->toBe('Lainnya (Sanggar seni)')
        ->and($lainnya->fresh()->weight_category)->toBe(WeightCategory::E);
});

// The drawer ---------------------------------------------------------------------------------------------------------

it('shows the field in the drawer only while "Lainnya" is chosen', function (): void {
    Livewire::actingAs($this->admin)->test(Form::class)
        ->call('open')
        ->assertSee('Jenis penyelenggara')
        ->assertDontSee('Jenis penyelenggara lainnya')
        ->set('form.organizer_type', OrganizerType::Lainnya->value)
        ->assertSee('Jenis penyelenggara lainnya')
        ->assertSee('Tulis jenisnya dengan singkat. Poin tetap mengikuti kategori bobot.')
        ->assertSeeHtml('placeholder="Contoh: komunitas, sanggar seni, media"')
        ->assertSeeHtml('maxlength="60"')
        ->set('form.organizer_type', OrganizerType::Ptn->value)
        ->assertDontSee('Jenis penyelenggara lainnya');
});

it('saves the typed kind from the drawer and drops it when another type was chosen meanwhile', function (): void {
    Livewire::actingAs($this->admin)->test(Form::class)
        ->call('open')
        ->set(F::fields(lainnyaForm('  Komunitas film pelajar ')))
        ->set('form.juknis', F::pdf())
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('lomba-saved');

    $competition = Competition::query()->sole();
    expect($competition->organizer_type_other)->toBe('Komunitas film pelajar');

    // The form still holds the text (the field is only hidden); the Action is what drops it.
    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->assertSet('form.organizer_type_other', 'Komunitas film pelajar')
        ->set('form.organizer_type', OrganizerType::Ptn->value)
        ->set('form.organizer_type_other', 'x')
        ->call('save')
        ->assertHasNoErrors();

    expect($competition->fresh()->organizer_type)->toBe(OrganizerType::Ptn)
        ->and($competition->fresh()->organizer_type_other)->toBeNull();
});

it('fills the drawer with the stored kind, for Kesiswaan and for Admin', function (RoleName $role): void {
    $competition = lainnyaPublished();

    Livewire::actingAs(F::staff($role, 'Staf '.$role->value))->test(Form::class)
        ->call('open', $competition->getKey())
        ->assertSet('formMode', 'edit')
        ->assertSet('form.organizer_type', 'lainnya')
        ->assertSet('form.organizer_type_other', 'Sanggar seni')
        ->assertSee('Jenis penyelenggara lainnya');
})->with([RoleName::Kesiswaan, RoleName::Admin]);

it('marks the field and brings it into view when "Simpan & publikasikan" misses the typed kind', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm(null), F::pdf());

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $competition->getKey())
        ->assertSet('formMode', 'verify')
        ->call('saveAndPublish')
        ->assertHasErrors(['form.organizer_type_other', 'decision'])
        ->assertSee('Lengkapi dulu: Jenis penyelenggara lainnya.')
        ->assertSee('Wajib diisi sebelum tampil di katalog.')
        ->assertDispatched('sth-form-invalid', form: Form::DIALOG)
        ->set('form.organizer_type_other', 'Sanggar seni')
        ->call('saveAndPublish')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Lomba dipublikasikan ke katalog.', tone: 'success');

    expect($competition->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($competition->fresh()->organizer_type_other)->toBe('Sanggar seni');
});

it('tells the 60-character limit on the field of the drawer', function (): void {
    Livewire::actingAs($this->admin)->test(Form::class)
        ->call('open')
        ->set(F::fields(lainnyaForm(str_repeat('a', 61))))
        ->call('save')
        ->assertHasErrors(['form.organizer_type_other'])
        ->assertSee('Maksimal 60 karakter.');

    expect(Competition::query()->count())->toBe(0);
});

it('names the missing kind on the card of the verification queue', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->admin, lainnyaForm(null), F::pdf());

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertSee('Perlu dilengkapi')
        ->assertSee('Jenis penyelenggara lainnya')
        ->assertDontSee('Data lengkap, siap dipublikasikan');

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, lainnyaForm('Sanggar seni'));

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertSee('Data lengkap, siap dipublikasikan')
        ->assertDontSee('Perlu dilengkapi');
});

// Where the type is shown ----------------------------------------------------------------------------------------------

it('shows "Lainnya (…)" on Guru › Lomba and keeps another guru\'s unpublished upload closed', function (): void {
    $retno = F::staff(RoleName::Guru, 'Bu Retno Wulandari');
    $hendra = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $published = lainnyaPublished();
    $queued = app(CreateCompetition::class)->handle($this->admin, lainnyaForm('Komunitas rahasia', ['name' => 'Lomba Esai Fiktif 2026']), F::pdf());
    $queued->forceFill(['submitted_by' => $hendra->getKey()])->save();

    Livewire::actingAs($retno)->withQueryParams(['lihat' => $published->getKey()])->test(GuruLomba::class)
        ->assertSee('Jenis penyelenggara')
        ->assertSee('Lainnya (Sanggar seni)');

    $this->actingAs($retno)->get(route('guru.lomba.index', ['lihat' => $queued->getKey()]))->assertNotFound();
    $this->actingAs($hendra)->get(route('guru.lomba.index', ['lihat' => $queued->getKey()]))->assertOk()->assertSee('Lainnya (Komunitas rahasia)');
});

it('shows "Lainnya (…)" in Database prestasi and in its Excel, and still filters by "Lainnya"', function (): void {
    $fixtures = new PrestasiFixtures;
    $nadia = F::student('Nadia Putri Anindya', 'XI-4', '225226201');
    $raka = F::student('Raka Pradipta', 'XII-2', '224225101');
    [$achievement] = $fixtures->prestasi([$nadia], lainnyaPublished(), ResultType::Juara1);
    $fixtures->prestasi([$raka], lainnyaPublished(['name' => 'Festival Rumus Fiktif 2026', 'organizer_type_other' => '=1+1']), ResultType::Juara2);
    $fixtures->prestasi([$raka], $fixtures->lomba('OPSI 2025', Bidang::Riset, Level::Nasional, '2025-09-01'), ResultType::Juara1);

    Livewire::actingAs($this->kesiswaan)->test(DatabasePrestasi::class)
        ->set('jenis', OrganizerType::Lainnya->value)
        ->assertSee('Festival Tari Pelajar Fiktif 2026')
        ->assertSee('Festival Rumus Fiktif 2026')
        ->assertDontSee('OPSI 2025')
        ->assertSee('Jenis penyelenggara: Lainnya')
        ->call('showDetail', (string) $achievement->getKey())
        ->assertSee('Lainnya (Sanggar seni)');

    $file = app(ExportAchievements::class)->handle($this->kesiswaan, AchievementFilters::fromInput([]));

    try {
        $rows = SimpleExcelReader::create($file->path)->noHeaderRow()->getRows()->all();
    } finally {
        @unlink($file->path);
    }

    $header = collect($rows)->first(fn (array $row): bool => ($row[0] ?? null) === 'No');
    $column = array_search('Jenis penyelenggara', $header, true);
    $types = collect($rows)->filter(fn (array $row): bool => is_numeric($row[0] ?? null))->map(fn (array $row): string => (string) $row[$column])->values()->all();

    expect($column)->not->toBeFalse()
        ->and($types)->toEqualCanonicalizing(['Lainnya (Sanggar seni)', 'Lainnya (=1+1)', 'Pemerintah pusat'])
        ->and(collect($types)->contains(fn (string $type): bool => str_starts_with($type, '=')))->toBeFalse();
});

it('does not show the typed kind to students or on the public pages', function (): void {
    $competition = lainnyaPublished(['organizer_type_other' => 'Sanggar tertutup']);
    $student = LombaFixtures::student();
    $student->forceFill(['publish_consent' => true])->save();
    (new PrestasiFixtures)->prestasi([$student], $competition, ResultType::Juara1);
    PublicAchievements::flush();

    $this->actingAs($student->user)->get(route('siswa.lomba.show', $competition))
        ->assertOk()
        ->assertSee('Festival Tari Pelajar Fiktif 2026')
        ->assertDontSee('Sanggar tertutup');

    auth()->logout();

    $this->get(route('public.lomba.pemenang', $competition))
        ->assertOk()
        ->assertSee('Festival Tari Pelajar Fiktif 2026')
        ->assertDontSee('Sanggar tertutup');
});

// The factory and the demo data -----------------------------------------------------------------------------------

it('gives every "lainnya" organizer of the demo data its typed kind', function (): void {
    /** @var array<string, list<string>> $organizers */
    $organizers = (new ReflectionClassConstant(DemoSeeder::class, 'ORGANIZERS'))->getValue();
    $others = collect($organizers)->filter(fn (array $preset): bool => $preset[1] === OrganizerType::Lainnya->value);

    expect($others)->not->toBeEmpty()
        ->and($organizers['sanggar'][4] ?? null)->toBe('Sanggar seni');

    foreach ($organizers as $key => $preset) {
        $other = $preset[4] ?? null;

        // Typed kind for "lainnya" only, within the column size; the published demo lomba must stay complete.
        expect($other !== null)->toBe($preset[1] === OrganizerType::Lainnya->value, $key)
            ->and(mb_strlen((string) $other))->toBeLessThanOrEqual(CreateCompetition::ORGANIZER_TYPE_OTHER_MAX, $key);
    }
});

it('builds a complete "Lainnya" lomba in the factory and leaves the kind empty for every other type', function (): void {
    expect(Competition::factory()->create(['organizer_type' => OrganizerType::Lainnya])->organizer_type_other)->toBeString()->not->toBe('')
        ->and(Competition::factory()->create(['organizer_type' => 'lainnya'])->organizer_type_other)->toBeString()->not->toBe('')
        ->and(Competition::factory()->create(['organizer_type' => OrganizerType::Lainnya, 'organizer_type_other' => 'Media'])->organizer_type_other)->toBe('Media')
        ->and(Competition::factory()->create(['organizer_type' => OrganizerType::Ptn])->organizer_type_other)->toBeNull()
        ->and(Competition::factory()->proposal()->create()->organizer_type_other)->toBeNull()
        ->and(VerifyCompetition::missingFields(Competition::factory()->create(['organizer_type' => OrganizerType::Lainnya])))->toBe([]);
});
