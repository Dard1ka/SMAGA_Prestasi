<?php

declare(strict_types=1);

use App\Actions\Competitions\CompetitionChange;
use App\Actions\Competitions\CompetitionDates;
use App\Actions\Competitions\UpdateCompetition;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\Competition;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Competitions\BinaanCompetitionChanged;
use App\Notifications\Competitions\CompetitionChanged;
use App\Notifications\Verification\Notice;
use App\Rules\ExternalUrl;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;
use Tests\Feature\Kesiswaan\LombaChangeFixtures as C;

/*
 * Lead's mail test of 2026-10-09, finding 10-7 (D-126): Kesiswaan changed the deadline of a published lomba while a
 * team was still collecting answers, and nobody was told. UpdateCompetition now tells the people attached to that
 * lomba (members of its running registrations and their guru pembimbing) when a fact they rely on changes:
 * batas daftar, tanggal, pelaksanaan, tempat, peserta, the two links, juknis. One notice per save per person; never
 * all students, never for a lomba that is not published, never for a typo fix in another field.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00:00'));
    F::boot();
    Notification::fake();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
});

const LCN_PRE_LOMBA = ['menunggu_konfirmasi', 'menunggu_kepsek', 'disetujui', 'surat_terbit', 'berlangsung'];

const LCN_NAMES = [
    'Raka Pradipta', 'Nadia Putri Anindya', 'Hana Safira', 'Rizky Maulana', 'Dimas Arya Saputra',
    'Salsabila Azzahra', 'Bagas Wicaksono', 'Citra Lestari', 'Fajar Nugroho', 'Intan Permatasari',
    'Sekar Ayu Lestari',
];

/** The one notice of that class the user got (fails on none or several: one notice per save per person). */
function lcnNotice(User $user, string $class = CompetitionChanged::class): Notice
{
    $sent = Notification::sent($user, $class);

    expect($sent)->toHaveCount(1);

    return $sent->first();
}

/** One registration of the lomba in every status, each with its own ketua. @return array<string, Student> status => ketua */
function lcnEveryStatus(Competition $competition, ?User $mentor = null): array
{
    $leaders = [];

    foreach (RegistrationStatus::cases() as $i => $status) {
        $leaders[$status->value] = F::student(LCN_NAMES[$i], 'XI-'.($i + 1));
        C::registration($competition, $status, $leaders[$status->value], $mentor);
    }

    return $leaders;
}

/** @param array<string, Student> $leaders @return list<string> the statuses whose ketua got a CompetitionChanged */
function lcnToldStatuses(array $leaders): array
{
    return array_keys(array_filter($leaders, fn (Student $leader): bool => Notification::sent($leader->user, CompetitionChanged::class)->isNotEmpty()));
}

// The facts ------------------------------------------------------------------------------------------------------

it('tells the ketua and, for what a guru relies on, the guru pembimbing when one fact changes', function (array $change, string $fact, string $sentence, bool $mentorTold): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $ketua = F::student('Raka Pradipta', 'XII-2');
    $competition = C::lomba();
    $registration = C::registration($competition, RegistrationStatus::MenungguKonfirmasi, $ketua, $guru);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, $change));

    /** @var CompetitionChanged $notice */
    $notice = lcnNotice($ketua->user);

    expect($notice->kind)->toBe('competition.changed')
        ->and($notice->title)->toBe('Info lomba berubah: '.C::NAME)
        ->and($notice->lines)->toBe([$sentence])
        ->and($notice->body)->toBe('Kesiswaan mengubah data lomba ini. '.$sentence)
        ->and($notice->actionLabel)->toBe('Lihat lomba')
        ->and($notice->url())->toBe(route('siswa.lomba-saya.show', ['registration' => $registration->getKey()]))
        ->and($notice->toArray($ketua->user))->toMatchArray([
            'kind' => 'competition.changed',
            'competition_id' => $competition->getKey(),
            'registration_id' => $registration->getKey(),
        ]);

    if ($mentorTold) {
        /** @var BinaanCompetitionChanged $forGuru */
        $forGuru = lcnNotice($guru, BinaanCompetitionChanged::class);

        expect($forGuru->kind)->toBe('competition.binaan_changed')
            ->and($forGuru->title)->toBe('Info lomba binaan berubah: '.C::NAME)
            ->and($forGuru->lines)->toBe([$sentence])
            ->and($forGuru->body)->toBe('Kesiswaan mengubah data lomba yang diikuti siswa binaan. '.$sentence)
            ->and($forGuru->actionLabel)->toBe('Lihat binaan')
            ->and($forGuru->url())->toBe(route('guru.binaan.show', ['registration' => $registration->getKey()]));
    } else {
        Notification::assertNotSentTo($guru, BinaanCompetitionChanged::class);
    }

    Notification::assertNothingSentTo($this->kesiswaan);

    expect(C::auditRows())->toBe([['changed' => [$fact], 'students' => 1, 'mentors' => $mentorTold ? 1 : 0, 'mail' => true]]);
})->with([
    'batas daftar' => [
        ['registration_closes_at' => '2026-10-09'], 'deadline',
        'Batas daftar sekarang 9 Okt 2026. Undangan tim yang belum dijawab tidak berlaku lagi setelah tanggal itu.', false,
    ],
    'tanggal mulai' => [['starts_on' => '2026-11-06'], 'dates', 'Tanggal lomba sekarang 6–8 Nov 2026.', true],
    'tanggal final' => [['final_on' => '2026-11-10'], 'dates', 'Tanggal lomba sekarang 7–10 Nov 2026.', true],
    'satu hari' => [['starts_on' => '2026-11-08'], 'dates', 'Tanggal lomba sekarang 8 Nov 2026.', true],
    'lintas bulan' => [['starts_on' => '2026-10-30'], 'dates', 'Tanggal lomba sekarang 30 Okt – 8 Nov 2026.', true],
    'lintas tahun' => [['final_on' => '2027-01-02'], 'dates', 'Tanggal lomba sekarang 7 Nov 2026 – 2 Jan 2027.', true],
    'pelaksanaan' => [['mode' => 'daring'], 'mode', 'Pelaksanaan sekarang Daring.', true],
    'tempat' => [['location' => 'Aula SMA Negeri 3 Semarang'], 'location', 'Tempat sekarang Aula SMA Negeri 3 Semarang.', true],
    'maks anggota' => [['max_team_size' => 5], 'participants', 'Peserta sekarang Individu atau tim (maks 5).', false],
    'peserta jadi tim' => [['participation' => 'tim'], 'participants', 'Peserta sekarang Tim, maks 3.', false],
    'link registrasi diganti' => [['registration_url' => 'https://example.org/nsif/daftar-baru'], 'registration_url', 'Link registrasi diganti.', true],
    'link registrasi dihapus' => [['registration_url' => ''], 'registration_url', 'Link registrasi dihapus.', true],
    'website diganti' => [['website_url' => 'https://example.org/nsif-2026'], 'website_url', 'Website resmi diganti.', true],
    'website dihapus' => [['website_url' => null], 'website_url', 'Website resmi dihapus.', true],
]);

it('tells about a new juknis, and never about a new cover', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $ketua = F::student('Raka Pradipta', 'XII-2');
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::SuratTerbit, $ketua, $guru);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition), null, UploadedFile::fake()->image('poster.png', 300, 400));

    Notification::assertNothingSent();
    expect(C::auditRows())->toBe([]);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition), F::pdf());

    expect(lcnNotice($ketua->user)->lines)->toBe(['Juknis/brosur diganti.'])
        ->and(lcnNotice($guru, BinaanCompetitionChanged::class)->lines)->toBe(['Juknis/brosur diganti.'])
        ->and(C::auditRows())->toBe([['changed' => ['juknis'], 'students' => 1, 'mentors' => 1, 'mail' => true]]);
});

it('says "Individu" for a lomba that takes single participants only', function (): void {
    $competition = C::lomba(['participation' => Participation::Individu, 'max_team_size' => null]);

    expect(CompetitionChange::line('participants', $competition))->toBe('Peserta sekarang Individu.');
});

it('lists every changed fact in one notice per person', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $ketua = F::student('Raka Pradipta', 'XII-2');
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::MenungguKonfirmasi, $ketua, $guru);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, [
        'registration_closes_at' => '2026-10-09', 'mode' => 'hybrid', 'location' => 'Aula SMA Negeri 3 Semarang', 'max_team_size' => 4,
    ]));

    // The student hears all four; the guru only what a guru relies on (not the deadline, not the team size).
    expect(lcnNotice($ketua->user)->lines)->toBe([
        'Batas daftar sekarang 9 Okt 2026. Undangan tim yang belum dijawab tidak berlaku lagi setelah tanggal itu.',
        'Pelaksanaan sekarang Hybrid.',
        'Tempat sekarang Aula SMA Negeri 3 Semarang.',
        'Peserta sekarang Individu atau tim (maks 4).',
    ])->and(lcnNotice($guru, BinaanCompetitionChanged::class)->lines)->toBe([
        'Pelaksanaan sekarang Hybrid.',
        'Tempat sekarang Aula SMA Negeri 3 Semarang.',
    ])->and(C::auditRows())->toBe([['changed' => ['deadline', 'mode', 'location', 'participants'], 'students' => 1, 'mentors' => 1, 'mail' => true]]);
});

it('names the answer window of a team resumed late instead of the new deadline (FS1 fix wave, D-148)', function (): void {
    $resumed = F::student('Raka Pradipta', 'XII-2');
    $shortGrace = F::student('Nadia Putri Anindya', 'XI-4');
    $plain = F::student('Hana Safira', 'XI-3');
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::MenungguKonfirmasi, $resumed)->forceFill(['answer_until' => '2026-10-12 09:00:00'])->save();
    C::registration($competition, RegistrationStatus::MenungguKonfirmasi, $shortGrace)->forceFill(['answer_until' => '2026-10-08 09:00:00'])->save();
    C::registration($competition, RegistrationStatus::MenungguKonfirmasi, $plain);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['registration_closes_at' => '2026-10-09']));

    // The 3-day window of the resumed team ends after the new deadline: its invitations stay valid until then.
    expect(lcnNotice($resumed->user)->lines)->toBe(['Batas daftar sekarang 9 Okt 2026. Undangan tim yang belum dijawab berlaku sampai 12 Okt 2026, 09.00.'])
        ->and(lcnNotice($shortGrace->user)->lines)->toBe(['Batas daftar sekarang 9 Okt 2026. Undangan tim yang belum dijawab tidak berlaku lagi setelah tanggal itu.'])
        ->and(lcnNotice($plain->user)->lines)->toBe(['Batas daftar sekarang 9 Okt 2026. Undangan tim yang belum dijawab tidak berlaku lagi setelah tanggal itu.']);
});

// What is not a fact ---------------------------------------------------------------------------------------------

it('sends nothing and writes no facts row when no fact changed', function (array $change): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::MenungguKonfirmasi, F::student('Raka Pradipta', 'XII-2'), $guru);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, $change));

    foreach ($change as $field => $value) {
        expect((string) $competition->fresh()->getRawOriginal($field))->toContain(trim((string) $value));
    }

    Notification::assertNothingSent();
    expect(C::auditRows())->toBe([]);
})->with([
    'nothing changed' => [[]],
    'nama' => [['name' => 'National Science and Invention Fair 2026']],
    'deskripsi' => [['description' => 'Deskripsi contoh yang diperbaiki.']],
    'penyelenggara' => [['organizer_name' => 'Yayasan Contoh Fiktif']],
    'jenis penyelenggara' => [['organizer_type' => 'lainnya', 'organizer_type_other' => 'Komunitas pelajar']],
    'bidang' => [['bidang' => 'sains']],
    'daftar dibuka' => [['registration_opens_at' => '2026-09-10']],
    'tempat, hanya huruf besar dan spasi' => [['location' => '  kota   SEMARANG ']],
]);

it('keeps a change of tingkat, kategori or status ajang to its own audit event', function (): void {
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::Disetujui, F::student('Raka Pradipta', 'XII-2'), F::staff(RoleName::Guru, 'Pak Hendra Kusuma'));

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, [
        'level' => 'provinsi', 'weight_category' => 'B', 'event_status' => 'pemerintah_non_puspresnas',
    ]));

    Notification::assertNothingSent();
    expect(C::auditRows())->toBe([])
        ->and(Activity::query()->where('event', 'competition.weights_changed')->count())->toBe(1);
});

it('does not count the default guru pembimbing as a fact', function (): void {
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::Disetujui, F::student('Raka Pradipta', 'XII-2'));

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, [
        'default_mentor_id' => F::staff(RoleName::Guru, 'Pak Hendra Kusuma')->getKey(),
    ]));

    Notification::assertNothingSent();
    expect(C::auditRows())->toBe([]);
});

it('compares the deadline by day and ignores a team size left on an individu lomba', function (): void {
    // Old rows: a deadline stored with another time of day, a "Maks anggota" left on an individu lomba. The save
    // rewrites both columns (23:59:59, null) although nothing a student relies on changed.
    $competition = C::lomba(['registration_closes_at' => '2026-10-20 12:00:00']);
    C::registration($competition, RegistrationStatus::MenungguKonfirmasi, F::student('Raka Pradipta', 'XII-2'));

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition));

    $individu = C::lomba(['name' => 'Olimpiade Kimia Fiktif 2026', 'participation' => Participation::Individu, 'max_team_size' => 3]);
    C::registration($individu, RegistrationStatus::MenungguKonfirmasi, F::student('Hana Safira', 'X-8'), null, RegistrationType::Individu);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $individu, C::input($individu));

    expect($competition->fresh()->registration_closes_at->format('Y-m-d H:i:s'))->toBe('2026-10-20 23:59:59')
        ->and($individu->fresh()->max_team_size)->toBeNull()
        ->and(C::auditRows())->toBe([]);

    Notification::assertNothingSent();
});

it('tells again, with the value as it is now, when a later save puts the old value back', function (): void {
    $ketua = F::student('Raka Pradipta', 'XII-2');
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::MenungguKepsek, $ketua);
    $update = app(UpdateCompetition::class);

    $update->handle($this->kesiswaan, $competition, C::input($competition, ['location' => 'Aula SMA Negeri 3 Semarang']));
    $update->handle($this->kesiswaan, $competition, C::input($competition));                                  // same values: silent
    $update->handle($this->kesiswaan, $competition, C::input($competition, ['location' => 'Kota Semarang'])); // back to the old place

    expect(Notification::sent($ketua->user, CompetitionChanged::class)->map(fn (CompetitionChanged $n): array => $n->lines)->all())->toBe([
        ['Tempat sekarang Aula SMA Negeri 3 Semarang.'],
        ['Tempat sekarang Kota Semarang.'],
    ])->and(C::auditRows())->toHaveCount(2);
});

// Which registrations --------------------------------------------------------------------------------------------

it('tells only the registrations that still rely on the changed fact', function (array $change, array $expected): void {
    $competition = C::lomba();
    $leaders = lcnEveryStatus($competition);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, $change));

    expect(lcnToldStatuses($leaders))->toBe($expected)
        ->and(C::auditRows()[0]['students'])->toBe(count($expected));
})->with([
    'batas daftar: only teams still collecting answers' => [['registration_closes_at' => '2026-10-09'], ['menunggu_konfirmasi']],
    'peserta: only teams still collecting answers' => [['max_team_size' => 5], ['menunggu_konfirmasi']],
    'tanggal mulai: until the lomba is over' => [['starts_on' => '2026-11-06'], LCN_PRE_LOMBA],
    'tanggal final: also while the report is open' => [['final_on' => '2026-11-10'], [...LCN_PRE_LOMBA, 'menunggu_laporan']],
    'pelaksanaan' => [['mode' => 'daring'], LCN_PRE_LOMBA],
    'tempat' => [['location' => 'Aula SMA Negeri 3 Semarang'], LCN_PRE_LOMBA],
    'link registrasi' => [['registration_url' => null], LCN_PRE_LOMBA],
    'website resmi' => [['website_url' => 'https://example.org/nsif-2026'], LCN_PRE_LOMBA],
    'batas daftar + tempat' => [['registration_closes_at' => '2026-10-09', 'location' => 'Daring'], LCN_PRE_LOMBA],
]);

it('gives each registration only the sentences of the facts it relies on', function (): void {
    $competition = C::lomba();
    $leaders = lcnEveryStatus($competition);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, [
        'registration_closes_at' => '2026-10-09', 'location' => 'Aula SMA Negeri 3 Semarang',
    ]));

    expect(lcnNotice($leaders['menunggu_konfirmasi']->user)->lines)->toHaveCount(2)
        ->and(lcnNotice($leaders['berlangsung']->user)->lines)->toBe(['Tempat sekarang Aula SMA Negeri 3 Semarang.']);
});

it('tells a registration that waits for its report about a new final date', function (): void {
    // D-151: no report deadline any more, also when the old setting is changed.
    Setting::put(SettingKey::ReportDeadlineDays, 30);

    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $waiting = F::student('Raka Pradipta', 'XII-2');
    $running = F::student('Hana Safira', 'X-8');
    $competition = C::lomba();
    $registration = C::registration($competition, RegistrationStatus::MenungguLaporan, $waiting, $guru);
    C::registration($competition, RegistrationStatus::Berlangsung, $running);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['final_on' => '2026-11-10']));

    expect(lcnNotice($waiting->user)->lines)->toBe(['Tanggal selesai sekarang 10 Nov 2026.'])
        ->and(lcnNotice($running->user)->lines)->toBe(['Tanggal lomba sekarang 7–10 Nov 2026.'])
        ->and(lcnNotice($guru, BinaanCompetitionChanged::class)->lines)->toBe(['Tanggal selesai sekarang 10 Nov 2026.'])
        ->and(lcnNotice($guru, BinaanCompetitionChanged::class)->url())->toBe(route('guru.binaan.show', ['registration' => $registration->getKey()]));
});

it('does not tell a registration that waits for its report when only the start date moved', function (): void {
    $waiting = F::student('Raka Pradipta', 'XII-2');
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::MenungguLaporan, $waiting, F::staff(RoleName::Guru, 'Pak Hendra Kusuma'));

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['starts_on' => '2026-11-06']));

    Notification::assertNothingSent();
    expect(C::auditRows())->toBe([['changed' => ['dates'], 'students' => 0, 'mentors' => 0, 'mail' => false]]);
});

// Which people ---------------------------------------------------------------------------------------------------

it('tells the ketua, the confirmed and the still-invited member of a team, never the member who declined', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $ketua = F::student('Raka Pradipta', 'XII-2');
    $confirmed = F::student('Nadia Putri Anindya', 'XI-4');
    $invited = F::student('Hana Safira', 'X-8');
    $declined = F::student('Rizky Maulana', 'XI-1');
    $outsider = F::student('Dimas Arya Saputra', 'XI-2');

    $competition = C::lomba();
    $registration = C::registration($competition, RegistrationStatus::MenungguKonfirmasi, $ketua, $guru);
    C::member($registration, $confirmed);
    C::member($registration, $invited, 'invited');
    C::member($registration, $declined, 'declined');

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['registration_closes_at' => '2026-10-09']));

    Notification::assertSentTo([$ketua->user, $confirmed->user, $invited->user], CompetitionChanged::class);
    Notification::assertNothingSentTo($declined->user);
    Notification::assertNothingSentTo($outsider->user);
    Notification::assertNothingSentTo($guru); // the deadline is the team's matter
    Notification::assertNothingSentTo($this->kesiswaan);

    expect(lcnNotice($invited->user)->url())->toBe(route('siswa.lomba-saya.show', ['registration' => $registration->getKey()]))
        ->and(C::auditRows())->toBe([['changed' => ['deadline'], 'students' => 3, 'mentors' => 0, 'mail' => true]]);
});

it('skips accounts that cannot log in, and never tells the account that made the change', function (): void {
    // The actor also holds the guru role and mentors one registration.
    $this->kesiswaan->assignRole(RoleName::Guru->value);

    $active = F::student('Raka Pradipta', 'XII-2');
    $pending = F::student('Nadia Putri Anindya', 'XI-4');
    $disabled = F::student('Hana Safira', 'X-8');
    $archived = F::student('Rizky Maulana', 'XI-1');

    $pending->user->forceFill(['status' => UserStatus::PendingActivation, 'email' => null, 'email_verified_at' => null])->save();
    $disabled->user->forceFill(['status' => UserStatus::Disabled])->save();
    $archived->user->forceFill(['status' => UserStatus::Archived])->save();

    $competition = C::lomba();
    $registration = C::registration($competition, RegistrationStatus::Disetujui, $active, $this->kesiswaan);
    C::member($registration, $pending);
    C::member($registration, $disabled);
    C::member($registration, $archived);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['location' => 'Aula SMA Negeri 3 Semarang']));

    Notification::assertSentTo([$active->user, $pending->user], CompetitionChanged::class);
    Notification::assertNothingSentTo($disabled->user);
    Notification::assertNothingSentTo($archived->user);
    Notification::assertNothingSentTo($this->kesiswaan);

    // A member who has not activated the account has no address yet: the bell row waits, no mail goes out.
    expect($pending->user->fresh()->routeNotificationForMail(lcnNotice($pending->user)))->toBeNull()
        ->and(C::auditRows())->toBe([['changed' => ['location'], 'students' => 2, 'mentors' => 0, 'mail' => true]]);
});

it('skips a guru pembimbing who is no longer a guru or cannot log in', function (): void {
    $moved = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $moved->syncRoles([RoleName::Humas->value]);
    $disabled = F::staff(RoleName::Guru, 'Bu Retno Wulandari');
    $disabled->forceFill(['status' => UserStatus::Disabled])->save();
    $active = F::staff(RoleName::Guru, 'Pak Bambang Sutrisno');

    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::Disetujui, F::student('Raka Pradipta', 'XII-2'), $moved);
    C::registration($competition, RegistrationStatus::SuratTerbit, F::student('Nadia Putri Anindya', 'XI-4'), $disabled);
    C::registration($competition, RegistrationStatus::Berlangsung, F::student('Hana Safira', 'X-8'), $active);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['mode' => 'daring']));

    Notification::assertNothingSentTo($moved);
    Notification::assertNothingSentTo($disabled);
    Notification::assertSentTo($active, BinaanCompetitionChanged::class);

    expect(C::auditRows())->toBe([['changed' => ['mode'], 'students' => 3, 'mentors' => 1, 'mail' => true]]);
});

it('sends one notice to a guru who mentors several registrations of the lomba', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::MenungguKepsek, F::student('Raka Pradipta', 'XII-2'), $guru);
    C::registration($competition, RegistrationStatus::SuratTerbit, F::student('Nadia Putri Anindya', 'XI-4'), $guru);
    // Not counted: this binaan no longer relies on the place.
    C::registration($competition, RegistrationStatus::Selesai, F::student('Hana Safira', 'X-8'), $guru);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['location' => 'Aula SMA Negeri 3 Semarang']));

    /** @var BinaanCompetitionChanged $notice */
    $notice = lcnNotice($guru, BinaanCompetitionChanged::class);

    expect($notice->lines)->toBe(['Tempat sekarang Aula SMA Negeri 3 Semarang.'])
        ->and($notice->url())->toBe(route('guru.beranda'))
        ->and($notice->toArray($guru)['registration_id'])->toBeNull()
        ->and(C::auditRows())->toBe([['changed' => ['location'], 'students' => 2, 'mentors' => 1, 'mail' => true]]);
});

// The mail limit -------------------------------------------------------------------------------------------------

it('sends mail and bell up to the limit, and only the bell above it', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $competition = C::lomba();
    $students = [];

    for ($i = 1; $i < CompetitionChange::MAIL_LIMIT; $i++) {
        $students[] = $student = F::student('Siswa Contoh '.$i, 'X-1');
        C::registration($competition, RegistrationStatus::Disetujui, $student, $guru, RegistrationType::Individu);
    }

    // 49 students + 1 guru = the limit.
    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['location' => 'Aula SMA Negeri 3 Semarang']));

    Notification::assertSentTo($students[0]->user, CompetitionChanged::class, fn (CompetitionChanged $n, array $channels): bool => $channels === ['mail', 'database']);
    Notification::assertSentTo($guru, BinaanCompetitionChanged::class, fn (BinaanCompetitionChanged $n, array $channels): bool => $channels === ['mail', 'database']);

    $students[] = $last = F::student('Siswa Contoh '.CompetitionChange::MAIL_LIMIT, 'X-1');
    C::registration($competition, RegistrationStatus::Disetujui, $last, $guru, RegistrationType::Individu);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['location' => 'Kota Semarang']));

    foreach ([$students[0], $last] as $student) {
        Notification::assertSentTo($student->user, CompetitionChanged::class, fn (CompetitionChanged $n, array $channels): bool => $channels === ['database']
            && $n->lines === ['Tempat sekarang Kota Semarang.']);
    }

    Notification::assertSentTo($guru, BinaanCompetitionChanged::class, fn (BinaanCompetitionChanged $n, array $channels): bool => $channels === ['database']);

    expect(C::auditRows())->toBe([
        ['changed' => ['location'], 'students' => 49, 'mentors' => 1, 'mail' => true],
        ['changed' => ['location'], 'students' => 50, 'mentors' => 1, 'mail' => false],
    ]);
});

// Never --------------------------------------------------------------------------------------------------------

it('stays silent for a lomba that is not published', function (): void {
    $ketua = F::student('Raka Pradipta', 'XII-2');
    $competition = Competition::factory()->create([...F::form([
        'registration_opens_at' => '2026-09-15 00:00:00', 'registration_closes_at' => '2026-10-20 23:59:59',
    ]), 'name' => 'Lomba Esai Fiktif 2026'])->fresh();
    C::registration($competition, RegistrationStatus::MenungguKonfirmasi, $ketua, F::staff(RoleName::Guru, 'Pak Hendra Kusuma'));

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, [
        'registration_closes_at' => '2026-10-09', 'location' => 'Aula SMA Negeri 3 Semarang', 'mode' => 'luring',
    ]));

    expect($competition->fresh()->location)->toBe('Aula SMA Negeri 3 Semarang')
        ->and(C::auditRows())->toBe([]);

    Notification::assertNothingSent();
});

it('writes the facts row with zero counts when nobody is attached to the lomba', function (): void {
    $competition = C::lomba();

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['location' => 'Aula SMA Negeri 3 Semarang']));

    $row = Activity::query()->where('event', 'competition.facts_changed')->sole();

    expect($row->properties->toArray())->toBe(['changed' => ['location'], 'students' => 0, 'mentors' => 0, 'mail' => false])
        ->and($row->causer_id)->toBe($this->kesiswaan->getKey())
        ->and($row->subject_id)->toBe($competition->getKey())
        ->and($row->log_name)->toBe('competition');

    Notification::assertNothingSent();
});

it('keeps names, account ids and addresses out of the facts row', function (): void {
    $guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
    $ketua = F::student('Raka Pradipta', 'XII-2');
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::Disetujui, $ketua, $guru);

    app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, [
        'registration_url' => 'https://example.org/nsif/daftar-baru',
    ]));

    $properties = (string) json_encode(Activity::query()->where('event', 'competition.facts_changed')->sole()->properties);

    expect($properties)->not->toContain('Raka')
        ->not->toContain('Hendra')
        ->not->toContain('example.org')
        ->not->toContain((string) $ketua->getKey());
});

it('sends nothing when the save is refused', function (array $change, string $field, string $message): void {
    $competition = C::lomba();
    $registration = C::registration($competition, RegistrationStatus::MenungguKonfirmasi, F::student('Raka Pradipta', 'XII-2'), F::staff(RoleName::Guru, 'Pak Hendra Kusuma'));

    try {
        // Every case also changes the place, which would be told if the save went through.
        app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['location' => 'Aula SMA Negeri 3 Semarang', ...$change]));
        $this->fail('The save was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors()[$field][0] ?? null)->toBe($message);
    }

    expect($competition->fresh()->location)->toBe('Kota Semarang')
        ->and($registration->fresh()->status)->toBe(RegistrationStatus::MenungguKonfirmasi)
        ->and(C::auditRows())->toBe([]);

    Notification::assertNothingSent();
})->with([
    'lower team size while a team runs' => [['max_team_size' => 2], 'max_team_size', UpdateCompetition::TEAMS_RUNNING_MAX],
    'individu while a team runs' => [['participation' => 'individu'], 'participation', UpdateCompetition::TEAMS_RUNNING_PARTICIPATION],
    'start before the deadline' => [['starts_on' => '2026-10-10', 'final_on' => '2026-10-10'], 'starts_on', CompetitionDates::STARTS_BEFORE_DEADLINE],
    'published lomba left incomplete' => [['location' => ''], 'location', 'Wajib diisi sebelum tampil di katalog.'],
    'link that is not https' => [['registration_url' => 'http://example.org/daftar'], 'registration_url', ExternalUrl::HTTP],
]);

it('sends nothing and keeps no facts row when the save fails after the row was written', function (): void {
    $ketua = F::student('Raka Pradipta', 'XII-2');
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::Disetujui, $ketua, F::staff(RoleName::Guru, 'Pak Hendra Kusuma'));

    // The last write of the transaction fails: the lomba, the audit row and the notices go back together.
    Activity::created(function (Activity $row): void {
        if ($row->event === 'competition.facts_changed') {
            throw new RuntimeException('Simulated failure after the audit row.');
        }
    });

    expect(fn () => app(UpdateCompetition::class)->handle($this->kesiswaan, $competition, C::input($competition, ['location' => 'Aula SMA Negeri 3 Semarang'])))
        ->toThrow(RuntimeException::class, 'Simulated failure after the audit row.');

    expect($competition->fresh()->location)->toBe('Kota Semarang')
        ->and(C::auditRows())->toBe([]);

    Notification::assertNothingSent();
});

it('refuses every other role, sends nothing and writes nothing', function (RoleName $role): void {
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::MenungguKonfirmasi, F::student('Raka Pradipta', 'XII-2'));

    $actor = $role === RoleName::Siswa ? F::student('Nadia Putri Anindya', 'XI-4')->user : F::staff($role, 'Akun Contoh');

    expect(fn () => app(UpdateCompetition::class)->handle($actor, $competition, C::input($competition, ['location' => 'Aula SMA Negeri 3 Semarang'])))
        ->toThrow(AuthorizationException::class);

    expect($competition->fresh()->location)->toBe('Kota Semarang')
        ->and(C::auditRows())->toBe([]);

    Notification::assertNothingSent();
})->with([
    'siswa' => [RoleName::Siswa],
    'guru' => [RoleName::Guru],
    'humas' => [RoleName::Humas],
    'tata usaha' => [RoleName::TataUsaha],
    'kepala sekolah' => [RoleName::KepalaSekolah],
]);

it('lets the Admin change a published lomba with the same notices', function (): void {
    $admin = F::staff(RoleName::Admin, 'Operator STH');
    $ketua = F::student('Raka Pradipta', 'XII-2');
    $competition = C::lomba();
    C::registration($competition, RegistrationStatus::Disetujui, $ketua);

    app(UpdateCompetition::class)->handle($admin, $competition, C::input($competition, ['location' => 'Aula SMA Negeri 3 Semarang']));

    expect(lcnNotice($ketua->user)->lines)->toBe(['Tempat sekarang Aula SMA Negeri 3 Semarang.']);
    Notification::assertNothingSentTo($this->kesiswaan); // never the other staff, never all students
});

// The helper -----------------------------------------------------------------------------------------------------

it('maps every fact to its statuses and to who hears about it', function (): void {
    $pre = [
        RegistrationStatus::MenungguKonfirmasi, RegistrationStatus::MenungguKepsek, RegistrationStatus::Disetujui,
        RegistrationStatus::SuratTerbit, RegistrationStatus::Berlangsung,
    ];

    expect(CompetitionChange::FACTS)->toBe(['deadline', 'dates', 'mode', 'location', 'participants', 'registration_url', 'website_url', 'juknis'])
        ->and(CompetitionChange::MAIL_LIMIT)->toBe(50)
        ->and(CompetitionChange::statusesFor('deadline'))->toBe([RegistrationStatus::MenungguKonfirmasi])
        ->and(CompetitionChange::statusesFor('participants'))->toBe([RegistrationStatus::MenungguKonfirmasi])
        ->and(CompetitionChange::statusesFor('dates'))->toBe($pre)
        ->and(CompetitionChange::statusesFor('dates', true))->toBe([...$pre, RegistrationStatus::MenungguLaporan])
        ->and(CompetitionChange::statusesFor('location', true))->toBe($pre)
        ->and(CompetitionChange::statusesFor('nama'))->toBe([]);

    foreach (['mode', 'location', 'registration_url', 'website_url', 'juknis'] as $fact) {
        expect(CompetitionChange::statusesFor($fact))->toBe($pre)
            ->and(CompetitionChange::tellsMentor($fact))->toBeTrue();
    }

    expect(CompetitionChange::tellsMentor('dates'))->toBeTrue()
        ->and(CompetitionChange::tellsMentor('deadline'))->toBeFalse()
        ->and(CompetitionChange::tellsMentor('participants'))->toBeFalse();
});

it('never prints a web address in a sentence', function (): void {
    $competition = C::lomba();

    foreach (CompetitionChange::FACTS as $fact) {
        foreach ([null, RegistrationStatus::MenungguLaporan] as $status) {
            expect(CompetitionChange::line($fact, $competition, $status))->not->toContain('http')->not->toContain('example.org');
        }
    }

    // "Tempat" is typed by people and may hold an address (the field has no NoWebAddress rule): then the sentence
    // only says that the place changed, like the two link fields. Mail clients link such text by themselves (D-76).
    foreach ([
        'Zoom, zoom.us/j/123456',
        'Aula, info https://example.org/x',
        'www.panitia.example',
        "Daring lewat Zoom\n  zoom.us/j/123456.",
    ] as $place) {
        $competition->location = $place;

        foreach ([null, RegistrationStatus::MenungguLaporan] as $status) {
            expect(CompetitionChange::line('location', $competition, $status))->toBe('Tempat diganti.');
        }
    }

    // A street address with dots is no web address: printed as typed.
    $competition->location = 'Gedung Serbaguna Lt. 2, Jl. Contoh No. 5';

    expect(CompetitionChange::line('location', $competition))->toBe('Tempat sekarang Gedung Serbaguna Lt. 2, Jl. Contoh No. 5.');

    $competition->location = null;

    expect(CompetitionChange::line('location', $competition))->toBe('Tempat sekarang belum diisi.');
});
