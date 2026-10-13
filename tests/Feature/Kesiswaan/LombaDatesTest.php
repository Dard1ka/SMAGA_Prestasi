<?php

declare(strict_types=1);

use App\Actions\Competitions\CompetitionDates;
use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\UpdateCompetition;
use App\Actions\Competitions\VerifyCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Form;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Models\Competition;
use App\Notifications\Competitions\CompetitionRejected;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| M2-B (QA 2026-10-05): lomba dates must make sense (Q1-04) and a rejection needs a real reason (Q1-18).
| "Today" is fixed at 6 Oct 2026, so the dates below read the same on every run. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    // Only siswa and Admin add a lomba (D-145).
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

/** A complete queued lomba with the given dates (Y-m-d; the deadline is stored at 23:59:59 like the form does). */
function m2bQueued(string $closes, string $starts, string $final, array $extra = []): Competition
{
    return Competition::factory()->create(array_merge([
        'name' => 'Lomba Karya Tulis Ilmiah Fiktif',
        'registration_opens_at' => '2026-06-01 00:00:00',
        'registration_closes_at' => $closes.' 23:59:59',
        'starts_on' => $starts,
        'final_on' => $final,
    ], $extra))->fresh();
}

/** The form the drawer would send for a stored lomba (its own dates), with overrides. */
function m2bFormOf(Competition $competition, array $overrides = []): array
{
    return F::form(array_merge([
        'name' => $competition->name,
        'registration_opens_at' => $competition->registration_opens_at?->format('Y-m-d'),
        'registration_closes_at' => $competition->registration_closes_at?->format('Y-m-d'),
        'starts_on' => $competition->starts_on?->format('Y-m-d'),
        'final_on' => $competition->final_on?->format('Y-m-d'),
    ], $overrides));
}

// --- Order: "tanggal mulai" is never before "batas daftar" ------------------------------------------------

it('refuses a lomba that starts before its registration deadline when it is created', function (): void {
    $create = app(CreateCompetition::class);

    try {
        $create->handle($this->admin, F::form(['registration_closes_at' => '2026-10-20', 'starts_on' => '2026-10-19', 'final_on' => '2026-10-25']), F::pdf());
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('starts_on')
            ->and($e->errors()['starts_on'][0])->toBe('Lomba tidak boleh dimulai sebelum pendaftaran ditutup.');
    }

    expect(Competition::query()->count())->toBe(0);

    // The same day is fine (registration closes at 23:59, the lomba runs that day), and so are the old rules.
    expect($create->handle($this->admin, F::form(['registration_closes_at' => '2026-10-20', 'starts_on' => '2026-10-20', 'final_on' => '2026-10-20']), F::pdf())->exists)->toBeTrue()
        ->and(fn () => $create->handle($this->admin, F::form(['final_on' => '2026-11-01']), F::pdf()))->toThrow(ValidationException::class, 'Tanggal selesai tidak boleh sebelum tanggal mulai.')
        ->and(fn () => $create->handle($this->admin, F::form(['registration_closes_at' => '2026-09-01']), F::pdf()))->toThrow(ValidationException::class, 'Pendaftaran ditutup tidak boleh sebelum pendaftaran dibuka.');
});

it('reports the date order together with the other field errors', function (): void {
    try {
        app(CreateCompetition::class)->handle($this->admin, F::form(['website_url' => 'http://tidak-aman.test', 'starts_on' => '2026-10-01']), F::pdf());
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['website_url', 'starts_on']);
    }
});

it('accepts dates in the past when a lomba is created or saved: old lomba are entered for the record', function (): void {
    $competition = app(CreateCompetition::class)->handle($this->admin, F::form([
        'registration_opens_at' => '2026-06-01', 'registration_closes_at' => '2026-08-01', 'starts_on' => '2026-08-10', 'final_on' => '2026-08-11',
    ]), F::pdf());

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, m2bFormOf($competition->fresh(), ['location' => 'Kota Semarang']));

    expect($competition->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and($competition->fresh()->location)->toBe('Kota Semarang')
        ->and($competition->fresh()->final_on->toDateString())->toBe('2026-08-11');
});

it('refuses the wrong order when a queued lomba is updated', function (): void {
    $competition = m2bQueued('2026-10-20', '2026-11-07', '2026-11-07');

    expect(fn () => app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, m2bFormOf($competition, ['starts_on' => '2026-10-10'])))
        ->toThrow(ValidationException::class, 'Lomba tidak boleh dimulai sebelum pendaftaran ditutup.');

    expect($competition->fresh()->starts_on->toDateString())->toBe('2026-11-07');
});

it('keeps a published lomba with old dates editable and only refuses a newly wrong order', function (): void {
    // Published before the rule existed: starts before its deadline, and everything is over (the QA case).
    $old = Competition::factory()->published()->create([
        'name' => 'Lomba Cerdas Cermat Fiktif 2026',
        'registration_opens_at' => '2026-06-01 00:00:00',
        'registration_closes_at' => '2026-09-01 23:59:59',
        'starts_on' => '2026-08-01',
        'final_on' => '2026-08-02',
    ])->fresh();

    $update = app(UpdateCompetition::class);

    // Another field, the dates as they are: not blocked.
    $update->handle($this->kesiswaan, $old, m2bFormOf($old, ['location' => 'Aula SMAGA', 'organizer_name' => 'Panitia Cerdas Cermat']));

    expect($old->fresh()->location)->toBe('Aula SMAGA')
        ->and($old->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($old->fresh()->starts_on->toDateString())->toBe('2026-08-01');

    // Touching one of the two dates brings the rule back.
    expect(fn () => $update->handle($this->kesiswaan, $old->fresh(), m2bFormOf($old, ['starts_on' => '2026-08-05', 'final_on' => '2026-08-06'])))
        ->toThrow(ValidationException::class, 'Lomba tidak boleh dimulai sebelum pendaftaran ditutup.');
    expect($old->fresh()->starts_on->toDateString())->toBe('2026-08-01');

    // Fixing the order works, also with dates in the past and without any confirmation.
    $update->handle($this->kesiswaan, $old->fresh(), m2bFormOf($old, ['registration_closes_at' => '2026-07-25']));
    expect($old->fresh()->registration_closes_at->toDateString())->toBe('2026-07-25');
});

// --- Past dates: publishing asks first -------------------------------------------------------------------

it('publishes a lomba whose dates have passed only after an explicit confirmation, and audits it', function (): void {
    $competition = m2bQueued('2026-08-01', '2026-08-10', '2026-08-11', ['submitted_by' => $this->kesiswaan->getKey()]);
    $verify = app(VerifyCompetition::class);

    try {
        $verify->handle($this->kesiswaan, $competition, CompetitionStatus::Dipublikasikan);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey(CompetitionDates::PAST_KEY)
            ->and($e->errors()[CompetitionDates::PAST_KEY][0])
            ->toBe('Tanggal lomba ini sudah lewat (pendaftaran ditutup 1 Agt 2026, tanggal selesai 11 Agt 2026). Tetap publikasikan?');
    }

    expect($competition->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and(Activity::query()->where('event', 'competition.published')->exists())->toBeFalse();

    $verify->handle($this->kesiswaan, $competition->fresh(), CompetitionStatus::Dipublikasikan, null, true);

    expect($competition->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and(Activity::query()->where('event', 'competition.published')->sole()->properties['past_dates'])->toBe(['registration_closes_at', 'final_on']);
});

it('asks when only the registration deadline has passed, and not for a deadline that ends today', function (): void {
    $closed = m2bQueued('2026-10-05', '2026-10-12', '2026-10-13', ['submitted_by' => $this->kesiswaan->getKey()]);
    $today = m2bQueued('2026-10-06', '2026-10-12', '2026-10-13', ['name' => 'Lomba Poster Fiktif', 'submitted_by' => $this->kesiswaan->getKey()]);
    $verify = app(VerifyCompetition::class);

    expect(fn () => $verify->handle($this->kesiswaan, $closed, CompetitionStatus::Dipublikasikan))
        ->toThrow(ValidationException::class, 'Tanggal lomba ini sudah lewat (pendaftaran ditutup 5 Okt 2026). Tetap publikasikan?');

    $verify->handle($this->kesiswaan, $today, CompetitionStatus::Dipublikasikan);

    expect($closed->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and($today->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and(Activity::query()->where('event', 'competition.published')->sole()->properties['past_dates'])->toBe([]);
});

it('never publishes a queued lomba that starts before its deadline, confirmed or not', function (): void {
    // Queued before the rule existed.
    $competition = m2bQueued('2026-11-20', '2026-11-10', '2026-11-12');

    foreach ([false, true] as $confirmed) {
        try {
            app(VerifyCompetition::class)->handle($this->kesiswaan, $competition, CompetitionStatus::Dipublikasikan, null, $confirmed);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            expect($e->errors()['starts_on'][0] ?? null)->toBe(CompetitionDates::STARTS_BEFORE_DEADLINE);
        }
    }

    expect($competition->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi);
});

it('does not let a confirmation open publishing to other roles', function (RoleName $role): void {
    $competition = m2bQueued('2026-08-01', '2026-08-10', '2026-08-11');

    expect(fn () => app(VerifyCompetition::class)->handle(F::staff($role, 'Staf '.$role->value), $competition, CompetitionStatus::Dipublikasikan, null, true))
        ->toThrow(AuthorizationException::class);

    expect($competition->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi);
})->with([RoleName::Guru, RoleName::Humas, RoleName::TataUsaha, RoleName::KepalaSekolah]);

it('reads past dates from model values and from form values alike', function (): void {
    $now = CarbonImmutable::parse('2026-10-06 09:00:00');

    expect(CompetitionDates::past('2026-10-05', '2026-10-05', $now))->toBe(['registration_closes_at' => 'pendaftaran ditutup 5 Okt 2026', 'final_on' => 'tanggal selesai 5 Okt 2026'])
        ->and(CompetitionDates::past('2026-10-06', '2026-10-06', $now))->toBe([])
        ->and(CompetitionDates::past('2026-10-06 08:00:00', null, $now))->toHaveKey('registration_closes_at')
        ->and(CompetitionDates::past(CarbonImmutable::parse('2026-10-06 23:59:59'), CarbonImmutable::parse('2026-10-07'), $now))->toBe([])
        ->and(CompetitionDates::past(null, '', $now))->toBe([])
        ->and(CompetitionDates::past('31-12-2020', ['x'], $now))->toBe([])
        ->and(CompetitionDates::startsBeforeDeadline('2026-10-19', '2026-10-20 23:59:59'))->toBeTrue()
        ->and(CompetitionDates::startsBeforeDeadline('2026-10-20', '2026-10-20 23:59:59'))->toBeFalse()
        ->and(CompetitionDates::startsBeforeDeadline(null, '2026-10-20'))->toBeFalse()
        ->and(CompetitionDates::startsBeforeDeadline('2026-10-19', ''))->toBeFalse();
});

// --- Screens ---------------------------------------------------------------------------------------------

it('asks "Tetap publikasikan?" in a dialog when "Publikasikan" is pressed on a lomba with past dates', function (): void {
    $past = m2bQueued('2026-08-01', '2026-08-10', '2026-08-11', ['submitted_by' => $this->kesiswaan->getKey()]);

    $component = Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertSee('Tanggal sudah lewat:')
        ->assertSee('pendaftaran ditutup 1 Agt 2026, tanggal selesai 11 Agt 2026')
        ->call('publish', $past->getKey())
        ->assertDispatched('sth-modal-open', id: 'lomba-tanggal-lewat')
        ->assertNotDispatched('sth-toast')
        ->assertSet('pastId', $past->getKey())
        ->assertSee('Tetap publikasikan?')
        ->assertSee('Tanggal lomba ini sudah lewat.');

    // The click only opened the dialog.
    expect($past->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi);

    $component->call('publishPast')
        ->assertDispatched('sth-modal-close', id: 'lomba-tanggal-lewat')
        ->assertDispatched('sth-toast', message: 'Lomba dipublikasikan ke katalog.', tone: 'success')
        ->assertSet('pastId', null);

    expect($past->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan)
        ->and($past->fresh()->verified_by)->toBe($this->kesiswaan->getKey());
});

it('publishes a lomba with coming dates at once and keeps the dialog state locked', function (): void {
    $coming = m2bQueued('2026-10-20', '2026-11-07', '2026-11-07', ['submitted_by' => $this->kesiswaan->getKey()]);

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertDontSee('Tanggal sudah lewat:')
        ->call('publish', $coming->getKey())
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', message: 'Lomba dipublikasikan ke katalog.', tone: 'success');

    expect($coming->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan);

    // No dialog was opened: there is nothing to confirm, and the browser cannot name a lomba itself.
    $past = m2bQueued('2026-08-01', '2026-08-10', '2026-08-11', ['name' => 'Lomba Poster Fiktif']);

    Livewire::actingAs($this->kesiswaan)->test(Index::class)->call('publishPast')->assertNotFound();
    expect(fn () => Livewire::actingAs($this->kesiswaan)->test(Index::class)->set('pastId', $past->getKey()))->toThrow(Exception::class);
    expect($past->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi);
});

it('shows a wrong date order on the queue card instead of a publish button', function (): void {
    $wrong = m2bQueued('2026-11-20', '2026-11-10', '2026-11-12');

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertSee('Perlu diperbaiki:')
        ->assertSee('Lomba tidak boleh dimulai sebelum pendaftaran ditutup.')
        ->assertDontSee('Data lengkap, siap dipublikasikan')
        ->assertDontSeeHtml("publish('".$wrong->getKey()."')")
        ->assertSeeHtml("edit('".$wrong->getKey()."')")
        // A forged call gets the reason.
        ->call('publish', $wrong->getKey())
        ->assertDispatched('sth-toast', message: 'Lomba tidak boleh dimulai sebelum pendaftaran ditutup.', tone: 'error');

    expect($wrong->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi);
});

it('needs the tick "Tetap publikasikan" in the drawer before "Simpan & publikasikan" on past dates', function (): void {
    $past = m2bQueued('2026-08-01', '2026-08-10', '2026-08-11', ['submitted_by' => $this->kesiswaan->getKey()]);

    $component = Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $past->getKey())
        ->assertSet('formMode', 'verify')
        ->assertSet('confirmPast', false)
        ->assertSee('Tanggal lomba ini sudah lewat. Tetap publikasikan.')
        ->assertSee('Sudah lewat: pendaftaran ditutup 1 Agt 2026, tanggal selesai 11 Agt 2026.')
        ->set('form.location', 'Kota Semarang')
        ->call('saveAndPublish')
        ->assertHasErrors('confirmPast')
        ->assertDispatched('sth-form-invalid')
        ->assertNotDispatched('lomba-saved');

    // Saved, not published.
    expect($past->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and($past->fresh()->location)->toBe('Kota Semarang');

    $component->set('confirmPast', true)
        ->call('saveAndPublish')
        ->assertHasNoErrors()
        ->assertDispatched('lomba-saved')
        ->assertDispatched('sth-toast', message: 'Lomba dipublikasikan ke katalog.', tone: 'success')
        ->assertSet('confirmPast', false);

    expect($past->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan);
});

it('shows the tick only for past dates and never asks it for "Simpan"', function (): void {
    $coming = m2bQueued('2026-10-20', '2026-11-07', '2026-11-07', ['submitted_by' => $this->kesiswaan->getKey()]);

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $coming->getKey())
        ->assertDontSee('Tetap publikasikan.')
        // The dates typed in the drawer are in the past now: "Simpan" still saves, the tick appears.
        ->set('form.registration_closes_at', '2026-09-01')
        ->set('form.starts_on', '2026-09-05')
        ->set('form.final_on', '2026-09-06')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('lomba-saved');

    expect($coming->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi)
        ->and($coming->fresh()->final_on->toDateString())->toBe('2026-09-06');

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $coming->getKey())
        ->assertSee('Tanggal lomba ini sudah lewat. Tetap publikasikan.');
});

it('marks "Tanggal mulai" in the drawer when it lies before "Batas daftar"', function (): void {
    Livewire::actingAs($this->admin)->test(Form::class)
        ->call('open')
        ->assertSee('Tidak boleh sebelum batas daftar.')
        ->set('form', F::form(['registration_closes_at' => '2026-10-20', 'starts_on' => '2026-10-01', 'final_on' => '2026-10-25']))
        ->set('juknis', F::pdf())
        ->call('save')
        ->assertHasErrors('form.starts_on')
        ->assertSee('Lomba tidak boleh dimulai sebelum pendaftaran ditutup.')
        ->assertNotDispatched('lomba-saved')
        ->set('form.starts_on', '2026-10-21')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('lomba-saved');

    expect(Competition::query()->sole()->starts_on->toDateString())->toBe('2026-10-21');
});

it('does not block editing a published lomba whose dates are over, and shows no tick there', function (): void {
    $old = Competition::factory()->published()->create([
        'name' => 'Lomba Cerdas Cermat Fiktif 2026',
        'registration_opens_at' => '2026-06-01 00:00:00',
        'registration_closes_at' => '2026-09-01 23:59:59',
        'starts_on' => '2026-08-01',
        'final_on' => '2026-08-02',
    ]);

    Livewire::actingAs($this->kesiswaan)->test(Form::class)
        ->call('open', $old->getKey())
        ->assertSet('formMode', 'edit')
        ->assertDontSee('Tetap publikasikan.')
        ->set('form.location', 'Aula SMAGA')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Perubahan lomba tersimpan.', tone: 'success');

    expect($old->fresh()->location)->toBe('Aula SMAGA')
        ->and($old->fresh()->status)->toBe(CompetitionStatus::Dipublikasikan);
});

// --- D-103 (the lead, 2026-10-06): the rejection reason is optional -----------------------------------------
// Takes back Q1-18 / D-95 for a lomba rejection: no reason and a short one are both accepted.

it('rejects a lomba without a reason or with a short one and still tells the submitter', function (?string $note, ?string $stored, string $body): void {
    $siswa = F::student('Raka Pradipta', 'XII-2')->user;
    $usulan = Competition::factory()->proposal()->create(['name' => 'ISPO 2027', 'submitted_by' => $siswa->getKey()]);

    app(VerifyCompetition::class)->handle($this->kesiswaan, $usulan, CompetitionStatus::Ditolak, $note);

    expect($usulan->fresh()->status)->toBe(CompetitionStatus::Ditolak)
        ->and($usulan->fresh()->verification_note)->toBe($stored);
    Notification::assertSentTo($siswa, CompetitionRejected::class, fn (CompetitionRejected $n): bool => $n->body === $body);
})->with([
    'no reason' => [null, null, CompetitionRejected::NO_REASON],
    'only spaces' => ['   ', null, CompetitionRejected::NO_REASON],
    'two letters' => ['ab', 'ab', 'Alasan Kesiswaan: ab'],
    'a sentence' => ['Info lama.', 'Info lama.', 'Alasan Kesiswaan: Info lama.'],
]);

it('still refuses a rejection reason longer than 1.000 characters', function (): void {
    $siswa = F::student('Raka Pradipta', 'XII-2')->user;
    $usulan = Competition::factory()->proposal()->create(['name' => 'ISPO 2027', 'submitted_by' => $siswa->getKey()]);

    expect(fn () => app(VerifyCompetition::class)->handle($this->kesiswaan, $usulan, CompetitionStatus::Ditolak, str_repeat('a', 1001)))
        ->toThrow(ValidationException::class, 'Catatan maksimal 1.000 karakter.');

    expect($usulan->fresh()->status)->toBe(CompetitionStatus::MenungguVerifikasi);
    Notification::assertNothingSent();
});

it('sends a rejection from the queue card without a reason', function (): void {
    $siswa = F::student('Raka Pradipta', 'XII-2')->user;
    $usulan = Competition::factory()->proposal()->create(['name' => 'ISPO 2027', 'submitted_by' => $siswa->getKey()]);

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->call('startReject', $usulan->getKey())
        ->assertSee('Boleh dikosongkan.')
        ->assertDontSee('Minimal 10 karakter.')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertSet('rejectingId', null)
        ->assertDispatched('sth-toast', message: 'Lomba ditolak. Pengirim diberi tahu.', tone: 'success');

    expect($usulan->fresh()->status)->toBe(CompetitionStatus::Ditolak)
        ->and($usulan->fresh()->verification_note)->toBeNull();
    Notification::assertSentToTimes($siswa, CompetitionRejected::class, 1);
});

it('says the submitter gets the reason when one was typed on the queue card', function (): void {
    $siswa = F::student('Raka Pradipta', 'XII-2')->user;
    $usulan = Competition::factory()->proposal()->create(['name' => 'ISPO 2027', 'submitted_by' => $siswa->getKey()]);

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->call('startReject', $usulan->getKey())
        ->set('rejectNote', 'ab')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Lomba ditolak. Pengirim menerima alasannya.', tone: 'success');

    expect($usulan->fresh()->verification_note)->toBe('ab');
});

// QA5 (2026-10-07): the toast only says the submitter was told when a notification really went out -----------

it('does not claim the submitter was told when nobody gets a notification', function (string $who): void {
    if ($who === 'own entry') {
        $submitter = $this->kesiswaan;
    } else {
        $submitter = F::student('Putri Maharani Kusuma', 'XII-1')->user;
        $submitter->forceFill(['status' => 'archived'])->save();
    }

    $usulan = Competition::factory()->proposal()->create(['name' => 'Lomba Uji Tolak 2026', 'submitted_by' => $submitter->getKey()]);

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->call('startReject', $usulan->getKey())
        ->set('rejectNote', 'Data lomba belum lengkap.')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Lomba ditolak.', tone: 'success');

    expect($usulan->fresh()->status)->toBe(CompetitionStatus::Ditolak);
    Notification::assertNothingSent();
})->with(['own entry', 'archived student']);
