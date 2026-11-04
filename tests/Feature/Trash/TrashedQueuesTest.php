<?php

declare(strict_types=1);

use App\Actions\Trash\RestoreFromTrash;
use App\Actions\Trash\TrashStudents;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Livewire\Guru\BinaanDetail;
use App\Livewire\Guru\Lomba as GuruLomba;
use App\Livewire\Kepsek\Persetujuan;
use App\Livewire\Kesiswaan\Beranda as KesiswaanBeranda;
use App\Livewire\Kesiswaan\VerifikasiHasil;
use App\Livewire\Sekolah\Dashboard;
use App\Livewire\TataUsaha\ArsipSurat;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\Competition;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\Report;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Providers\NavigationServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Guru\GuruFixtures as G;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
 * smaga_server, the trash of 5 Nov 2026: a student in the trash ("Sampah": its `students` and `users` rows
 * soft-deleted, status unchanged) leaves the staff queues. A registration whose KETUA is in the trash is hidden from
 * Persetujuan lomba, the letter queue, Verifikasi hasil, the school dashboard and every sidebar count, and comes back on
 * "Pulihkan"; a registration with a trashed anggota stays, but every member list skips that student. Fictional people
 * only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Jakarta'));

    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
    $this->kepsek = F::staff(RoleName::KepalaSekolah, 'Drs. Bambang Hartono, M.Pd.');
    $this->tu = F::staff(RoleName::TataUsaha, 'Sri Handayani');
    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->humas = F::staff(RoleName::Humas, 'Mas Galih Prasetyo');
    $this->guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');

    $this->nadia = F::student('Nadia Putri Anindya', 'XI-4');
    $this->dimas = F::student('Dimas Arya Nugroho', 'XI-4');
    $this->hana = F::student('Hana Safira', 'XI-6');
    $this->raka = F::student('Raka Pradipta', 'XI-6');
});

/**
 * Puts these students in the trash through the Admin's own Action.
 *
 * @return list<TrashEntry>
 */
function tqTrash(Student ...$students): array
{
    return app(TrashStudents::class)->handle(test()->admin, array_map(fn (Student $s): int => (int) $s->user_id, $students), 'Data ganda dari import.');
}

/** @param list<TrashEntry> $entries */
function tqRestore(array $entries): void
{
    foreach ($entries as $entry) {
        app(RestoreFromTrash::class)->handle(test()->admin, $entry);
    }
}

/** @return array{principal: list<string>, letters: list<string>, reports: list<string>} */
function tqQueues(): array
{
    $ids = fn ($query): array => $query->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all();

    return [
        'principal' => $ids(Registration::query()->awaitingPrincipal()),
        'letters' => $ids(Registration::query()->awaitingLetters()),
        'reports' => $ids(Report::query()->awaitingVerification()),
    ];
}

/** @return array{kepsek: int, tu: int, kesiswaan: int, humas: int} */
function tqCounts(): array
{
    return [
        'kepsek' => NavigationServiceProvider::liveCounts(test()->kepsek)['kepsek.persetujuan'],
        'tu' => NavigationServiceProvider::liveCounts(test()->tu)['tu.surat'],
        'kesiswaan' => NavigationServiceProvider::liveCounts(test()->kesiswaan)['kesiswaan.verifikasi'],
        'humas' => NavigationServiceProvider::liveCounts(test()->humas)['humas.verifikasi'],
    ];
}

/** @param array<string, mixed> $attributes */
function tqLomba(string $name, array $attributes = []): Competition
{
    return F::competition(['name' => $name, ...$attributes]);
}

it('hides the registrations and reports of a trashed ketua from every queue and count until they are restored', function (): void {
    // Ketua Nadia goes to the trash: her rows leave the queues. Ketua Hana stays; her anggota Dimas goes to the trash.
    $goneWaiting = G::registration(null, $this->nadia, RegistrationStatus::MenungguKepsek, [$this->raka]);
    $goneApproved = G::registration(null, $this->nadia, RegistrationStatus::Disetujui);
    $goneReport = F::pendingReport($this->nadia, [$this->raka]);
    $keptWaiting = G::registration(null, $this->hana, RegistrationStatus::MenungguKepsek, [$this->dimas]);
    $keptApproved = G::registration(null, $this->hana, RegistrationStatus::Disetujui, [$this->dimas]);
    $keptReport = F::pendingReport($this->hana, [$this->dimas]);

    $all = tqQueues();
    expect(tqCounts())->toBe(['kepsek' => 2, 'tu' => 2, 'kesiswaan' => 2, 'humas' => 2]);

    $entries = tqTrash($this->nadia, $this->dimas);

    expect(tqQueues())->toBe([
        'principal' => [(string) $keptWaiting->getKey()],
        'letters' => [(string) $keptApproved->getKey()],
        'reports' => [(string) $keptReport->getKey()],
    ])
        ->and(tqCounts())->toBe(['kepsek' => 1, 'tu' => 1, 'kesiswaan' => 1, 'humas' => 1])
        // Nothing was changed on the rows themselves: the trash only hides them.
        ->and($goneWaiting->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and($goneApproved->fresh()->status)->toBe(RegistrationStatus::Disetujui)
        ->and($goneReport->fresh()->status)->toBe(ReportStatus::Dikirim);

    tqRestore($entries);

    expect(tqQueues())->toBe($all)
        ->and(tqCounts())->toBe(['kepsek' => 2, 'tu' => 2, 'kesiswaan' => 2, 'humas' => 2]);
});

it('leaves a trashed ketua out of the school dashboard tiles', function (): void {
    G::registration(null, $this->nadia, RegistrationStatus::MenungguKepsek);
    G::registration(null, $this->hana, RegistrationStatus::MenungguKepsek, [$this->dimas]);
    // "Belum lapor > 14 hari": the lomba of the fixtures ended on 12 Sep 2026, no report was sent.
    G::registration(null, $this->nadia, RegistrationStatus::MenungguLaporan);
    G::registration(null, $this->hana, RegistrationStatus::MenungguLaporan, [$this->dimas]);

    $dashboard = Livewire::actingAs($this->kepsek)->test(Dashboard::class)
        ->assertViewHas('kpi', fn (array $kpi): bool => $kpi['menunggu'] === 2 && $kpi['lewat'] === 2);

    tqTrash($this->nadia, $this->dimas);

    $dashboard->call('$refresh')
        ->assertViewHas('kpi', fn (array $kpi): bool => $kpi['menunggu'] === 1 && $kpi['lewat'] === 1)
        ->assertSee('1 pendaftaran menunggu persetujuan');
});

it('lists neither the row of a trashed ketua nor a trashed anggota on Persetujuan lomba', function (): void {
    $later = ['starts_on' => '2026-11-20', 'final_on' => '2026-11-20'];
    G::registration(null, $this->nadia, RegistrationStatus::MenungguKepsek, [], tqLomba('Lomba Debat Bahasa Inggris Kota 2026', $later));
    $kept = G::registration(null, $this->hana, RegistrationStatus::MenungguKepsek, [$this->dimas, $this->raka], tqLomba('Olimpiade Sains Kota Semarang 2026', $later));

    tqTrash($this->nadia, $this->dimas);

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->assertDontSee('Lomba Debat Bahasa Inggris Kota 2026')
        ->assertSee('Olimpiade Sains Kota Semarang 2026')
        ->assertViewHas('rows', fn (Collection $rows): bool => $rows->modelKeys() === [$kept->getKey()]
            && $rows->first()->members->map(fn ($m) => $m->student?->user?->name)->sort()->values()->all() === ['Hana Safira', 'Raka Pradipta'])
        ->assertViewHas('summary', fn (array $summary): bool => $summary['menunggu'] === 1)
        // "Lomba terdekat": only the lomba of a listed row, counting the students outside the trash.
        ->assertViewHas('upcoming', fn (array $upcoming): bool => array_column($upcoming, 'name') === ['Olimpiade Sains Kota Semarang 2026']
            && str_starts_with($upcoming[0]['meta'], '2 siswa SMAGA'))
        ->assertDontSee('Dimas Arya Nugroho');
});

it('answers a decision on a row whose ketua went to the trash meanwhile with a toast, not an approval', function (string $method): void {
    $registration = G::registration(null, $this->nadia, RegistrationStatus::MenungguKepsek);
    $screen = Livewire::actingAs($this->kepsek)->test(Persetujuan::class)->assertSee('Nadia Putri Anindya');

    tqTrash($this->nadia);

    $screen->call($method, $registration->getKey())
        ->assertOk()
        ->assertDispatched('sth-toast', message: Persetujuan::MSG_NOT_LISTED, tone: 'warning')
        ->assertSet('rejecting', null)
        ->assertDontSee('Nadia Putri Anindya');

    expect($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKepsek)
        ->and($registration->fresh()->principal_decided_at)->toBeNull();
})->with(['approve', 'openReject']);

it('keeps the approved row of a trashed ketua out of the letter queue, also behind an old link', function (): void {
    $gone = G::registration(null, $this->nadia, RegistrationStatus::Disetujui, [], tqLomba('Lomba Debat Bahasa Inggris Kota 2026'));
    G::registration(null, $this->hana, RegistrationStatus::Disetujui, [$this->dimas, $this->raka], tqLomba('Olimpiade Sains Kota Semarang 2026'));

    tqTrash($this->nadia, $this->dimas);

    Livewire::actingAs($this->tu)->test(SuratTugas::class)
        ->assertDontSee('Lomba Debat Bahasa Inggris Kota 2026')
        ->assertSee('Olimpiade Sains Kota Semarang 2026')
        // Hana and Raka: the trashed anggota is not counted in the queue card.
        ->assertSee('Tim 2 siswa')
        ->assertDontSee('Dimas Arya Nugroho');

    $this->actingAs($this->tu)->get(route('tu.surat.show', $gone))
        ->assertRedirect(route('tu.beranda'))
        ->assertSessionHas('toast', ['tone' => 'warning', 'message' => SuratTugas::MSG_CANCELLED_ELSEWHERE]);
});

it('keeps the report of a trashed ketua out of Verifikasi hasil and drops a trashed anggota from the team', function (): void {
    F::pendingReport($this->nadia, [$this->raka], ResultType::Juara3, null, tqLomba('Lomba Debat Bahasa Inggris Kota 2026'));
    $kept = F::pendingReport($this->hana, [$this->dimas, $this->raka], ResultType::Juara3, null, tqLomba('Olimpiade Sains Kota Semarang 2026'));

    tqTrash($this->nadia, $this->dimas);

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)
        ->assertDontSee('Lomba Debat Bahasa Inggris Kota 2026')
        ->assertSet('selectedId', $kept->getKey())
        // Queue card: the ketua and one other member (Raka), not two.
        ->assertViewHas('queue', fn (Collection $queue): bool => $queue->pluck('student')->all() === ['Hana Safira · XI-6 +1'])
        ->assertViewHas('detail', fn (array $detail): bool => array_column($detail['members'], 'name') === ['Hana Safira', 'Raka Pradipta'])
        ->assertDontSee('Dimas Arya Nugroho');

    Livewire::actingAs($this->kesiswaan)->test(KesiswaanBeranda::class)
        ->assertViewHas('reports', fn (Collection $reports): bool => $reports->pluck('title')->all() === ['Olimpiade Sains Kota Semarang 2026']);
});

it('answers a decision on a report whose ketua went to the trash meanwhile with a toast, not a verification', function (): void {
    $report = F::pendingReport($this->nadia);
    $screen = Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class)->assertSet('selectedId', $report->getKey());

    tqTrash($this->nadia);

    $screen->set('finalResult', ResultType::Juara3->value)
        ->set('checks', F::allChecks())
        ->call('verify')
        ->assertOk()
        ->assertDispatched('sth-toast', message: VerifikasiHasil::MSG_NOT_LISTED, tone: 'warning')
        ->assertSet('selectedId', null);

    expect($report->fresh()->status)->toBe(ReportStatus::Dikirim)
        ->and($report->achievements()->count())->toBe(0);
});

it('opens the queue instead of the old link of a report whose ketua is in the trash', function (): void {
    $gone = F::pendingReport($this->nadia, [], ResultType::Juara3, null, tqLomba('Lomba Debat Bahasa Inggris Kota 2026'));
    $kept = F::pendingReport($this->hana, [], ResultType::Juara3, null, tqLomba('Olimpiade Sains Kota Semarang 2026'));

    tqTrash($this->nadia);

    Livewire::actingAs($this->kesiswaan)->test(VerifikasiHasil::class, ['report' => $gone])
        ->assertSet('selectedId', $kept->getKey())
        ->assertDispatched('sth-toast', message: VerifikasiHasil::MSG_NOT_LISTED, tone: 'warning')
        ->assertDontSee('Lomba Debat Bahasa Inggris Kota 2026');
});

it('skips a trashed anggota on the guru pages and answers 404 once the whole team is in the trash', function (): void {
    $lomba = tqLomba('Olimpiade Sains Kota Semarang 2026');
    $team = G::registration($this->guru, $this->hana, RegistrationStatus::SuratTerbit, [$this->dimas], $lomba);
    $solo = G::registration($this->guru, $this->nadia, RegistrationStatus::SuratTerbit, [], tqLomba('Lomba Debat Bahasa Inggris Kota 2026'));

    tqTrash($this->dimas, $this->nadia);

    Livewire::actingAs($this->guru)->test(BinaanDetail::class, ['registration' => $team])
        ->assertViewHas('members', fn (array $members): bool => array_column($members, 'name') === ['Hana Safira'])
        ->assertDontSee('Dimas Arya Nugroho');

    Livewire::actingAs($this->guru)->withQueryParams(['lihat' => $lomba->getKey()])->test(GuruLomba::class)
        ->assertViewHas('detail', fn (?array $detail): bool => array_column($detail['binaan'] ?? [], 'name') === ['Hana Safira']);

    $this->actingAs($this->guru)->get(route('guru.binaan.show', $solo))->assertNotFound();
});

it('names only the students outside the trash in Arsip surat', function (): void {
    $registration = G::registration(null, $this->hana, RegistrationStatus::SuratTerbit, [$this->dimas, $this->raka], tqLomba('Olimpiade Sains Kota Semarang 2026'));
    Letter::factory()->create(['registration_id' => $registration->getKey()]);

    tqTrash($this->dimas);

    Livewire::actingAs($this->tu)->test(ArsipSurat::class)
        ->assertSee('Olimpiade Sains Kota Semarang 2026')
        ->assertSee('Hana Safira, Raka Pradipta')
        ->assertDontSee('Dimas Arya Nugroho');
});
