<?php

declare(strict_types=1);

use App\Actions\Publications\CompleteAppreciationEvent;
use App\Actions\Publications\RecordAppreciation;
use App\Actions\Publications\RecordAppreciations;
use App\Enums\AppreciationStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Appreciation;
use App\Models\Student;
use App\Notifications\Publications\AppreciationScheduled;
use App\Notifications\Publications\BinaanAppreciationNotice;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| D-124 (lead, 2026-10-10; hand test of 2026-10-09, finding 6-1): one "Masukkan" puts every ticked achievement on the
| event in one transaction (all or nothing) and tells each guru pembimbing ONCE, naming every binaan of that click.
| Before, a team of two was two clicks and two "Binaan masuk daftar apresiasi" mails. The student's own notice stays
| one per achievement; eligibility (verified, publish consent, on no other event: D-97) is unchanged.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = HumasFixtures::humas();
});

it('puts a team on an event in one call: one notice per student, ONE notice for the guru naming both', function (): void {
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    $mentor = $nadia->report->registration->mentor;
    $event = HumasFixtures::event('Upacara Senin', '2099-10-12', '07:00');

    $rows = app(RecordAppreciations::class)->handle($this->humas, $event, [$nadia, $raka]);

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (Appreciation $row): bool => $row->fresh()->status === AppreciationStatus::Dijadwalkan))->toBeTrue()
        ->and($rows->pluck('achievement_id')->sort()->values()->all())->toBe(collect([$nadia->getKey(), $raka->getKey()])->sort()->values()->all())
        ->and(Appreciation::query()->where('appreciation_event_id', $event->getKey())->count())->toBe(2);

    Notification::assertSentToTimes($nadia->student->user, AppreciationScheduled::class, 1);
    Notification::assertSentToTimes($raka->student->user, AppreciationScheduled::class, 1);
    Notification::assertSentToTimes($mentor, BinaanAppreciationNotice::class, 1);
    Notification::assertSentTimes(BinaanAppreciationNotice::class, 1);
    Notification::assertSentTo($mentor, BinaanAppreciationNotice::class, function (BinaanAppreciationNotice $n) use ($mentor, $nadia): bool {
        $body = $n->body();

        return $n->kind === BinaanAppreciationNotice::SCHEDULED
            && $n->title() === 'Binaan masuk daftar apresiasi'
            && count($n->binaan) === 2
            && str_starts_with($body, '2 prestasi binaan akan diapresiasi di Upacara Senin pada ')
            && str_contains($body, 'pukul 07.00 WIB: ')
            && str_contains($body, 'Nadia Putri Anindya (Juara II OPSI 2026)')
            && str_contains($body, 'Raka Pradipta (Juara II OPSI 2026)')
            && str_ends_with($body, '.')
            // One team = one registration: the link opens that binaan page.
            && $n->registrationId === $nadia->report->registration_id
            && $n->toMail($mentor) instanceof PlainMailMessage
            && html_entity_decode((string) $n->toMail($mentor)->greeting, ENT_QUOTES | ENT_HTML5) === 'Yth. Retno Wulandari, S.Pd.,';
    });
});

it('audits every row of a batch with the event date and time, without names', function (): void {
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    $event = HumasFixtures::event('Upacara Senin', now()->addDays(4)->toDateString(), '07:00');

    $rows = app(RecordAppreciations::class)->handle($this->humas, $event, [$nadia, $raka]);
    $logs = Activity::query()->where('event', 'appreciation.recorded')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('subject_id')->sort()->values()->all())->toBe($rows->map(fn (Appreciation $row): string => $row->getKey())->sort()->values()->all())
        ->and($logs->every(fn (Activity $log): bool => $log->causer_id === $this->humas->getKey()
            && $log->properties['appreciation_event_id'] === $event->getKey()
            && $log->properties['held_on'] === $event->held_on->toDateString()
            && $log->properties['held_time'] === '07:00'))->toBeTrue()
        ->and(json_encode($logs->pluck('properties')))->not->toContain('Nadia')->not->toContain('Raka');
});

it('sends one notice per guru, each listing only that guru\'s binaan', function (): void {
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    $keisha = HumasFixtures::achievement(studentName: 'Keisha Larasati', competitionName: 'FIKSI 2026');
    $first = $nadia->report->registration->mentor;
    $second = $keisha->report->registration->mentor;

    app(RecordAppreciations::class)->handle($this->humas, HumasFixtures::event(), [$keisha, $nadia, $raka]);

    Notification::assertSentTimes(BinaanAppreciationNotice::class, 2);
    Notification::assertSentTo($first, BinaanAppreciationNotice::class, fn (BinaanAppreciationNotice $n): bool => count($n->binaan) === 2
        && str_contains($n->body(), 'Nadia Putri Anindya') && str_contains($n->body(), 'Raka Pradipta')
        && ! str_contains($n->body(), 'Keisha')
        && $n->registrationId === $nadia->report->registration_id);
    // One binaan: the sentence it always was.
    Notification::assertSentTo($second, BinaanAppreciationNotice::class, fn (BinaanAppreciationNotice $n): bool => $n->binaan === ['Keisha Larasati (Juara II FIKSI 2026)']
        && str_starts_with($n->body(), 'Keisha Larasati (Juara II FIKSI 2026) akan diapresiasi di ')
        && ! str_contains($n->body(), 'Nadia')
        && $n->registrationId === $keisha->report->registration_id);
});

it('sends the guru to the list of binaan when one notice covers two registrations', function (): void {
    $nadia = HumasFixtures::achievement();
    $keisha = HumasFixtures::achievement(studentName: 'Keisha Larasati', competitionName: 'FIKSI 2026');
    $mentor = $nadia->report->registration->mentor;
    $keisha->report->registration->forceFill(['mentor_id' => $mentor->getKey()])->save();

    app(RecordAppreciations::class)->handle($this->humas, HumasFixtures::event(), [$nadia, $keisha->fresh()]);

    Notification::assertSentTimes(BinaanAppreciationNotice::class, 1);
    Notification::assertSentTo($mentor, BinaanAppreciationNotice::class, fn (BinaanAppreciationNotice $n): bool => count($n->binaan) === 2
        && $n->registrationId === null
        && str_contains($n->body(), 'OPSI 2026') && str_contains($n->body(), 'FIKSI 2026'));
});

it('tells no guru who can no longer log in, and no archived student, while the others still hear', function (): void {
    $nadia = HumasFixtures::achievement();
    $bima = HumasFixtures::teammate($nadia, 'Bima Satria Wicaksono');
    $keisha = HumasFixtures::achievement(studentName: 'Keisha Larasati', competitionName: 'FIKSI 2026');
    $bima->student->user->forceFill(['status' => UserStatus::Archived])->save();
    $keisha->report->registration->mentor->forceFill(['status' => UserStatus::Disabled])->save();

    app(RecordAppreciations::class)->handle($this->humas, HumasFixtures::event(), [$nadia, $bima->fresh(), $keisha->fresh()]);

    expect(Appreciation::query()->count())->toBe(3)
        ->and(RecordAppreciations::toldStudents([$nadia, $bima->fresh(), $keisha->fresh()]))->toBe(2);

    Notification::assertSentToTimes($nadia->student->user, AppreciationScheduled::class, 1);
    Notification::assertSentToTimes($keisha->student->user, AppreciationScheduled::class, 1);
    Notification::assertNotSentTo($bima->student->user, AppreciationScheduled::class);
    Notification::assertNotSentTo($keisha->report->registration->mentor, BinaanAppreciationNotice::class);
    // The alumnus is still named to the guru: the achievement is appreciated either way.
    Notification::assertSentTo($nadia->report->registration->mentor, BinaanAppreciationNotice::class, fn (BinaanAppreciationNotice $n): bool => count($n->binaan) === 2
        && str_contains($n->body(), 'Bima Satria Wicaksono'));
});

it('keeps the student\'s own notice one per achievement, also for two achievements in one batch', function (): void {
    $opsi = HumasFixtures::achievement();
    $fiksi = HumasFixtures::achievement(studentName: 'Salsabila Kirana', competitionName: 'FIKSI 2026');
    $fiksi->forceFill(['student_id' => $opsi->student_id])->save();

    app(RecordAppreciations::class)->handle($this->humas, HumasFixtures::event(), [$opsi, $fiksi->fresh()]);

    Notification::assertSentToTimes($opsi->student->user, AppreciationScheduled::class, 2);
    expect(RecordAppreciations::toldStudents([$opsi, $fiksi->fresh()]))->toBe(1);
});

it('is all or nothing: one achievement that may not go on the event stops the whole batch', function (Closure $spoil, string $message): void {
    $event = HumasFixtures::event();
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::achievement(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    $bad = $spoil($this->humas);
    Notification::fake();
    $before = Appreciation::query()->count();

    expect(fn () => app(RecordAppreciations::class)->handle($this->humas, $event, [$nadia, $bad, $raka]))
        ->toThrow(ValidationException::class, $message);

    expect(Appreciation::query()->count())->toBe($before)
        ->and(Appreciation::query()->where('appreciation_event_id', $event->getKey())->count())->toBe(0)
        ->and(Activity::query()->where('event', 'appreciation.recorded')->where('properties->appreciation_event_id', $event->getKey())->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'no publish consent' => [
        fn () => HumasFixtures::achievement(['publish_consent' => false], studentName: 'Fajar Ramadhan'),
        'Siswa belum memberi izin publikasi.',
    ],
    'not verified' => [
        fn () => HumasFixtures::achievement(studentName: 'Fajar Ramadhan', verified: false),
        'Prestasi ini belum terverifikasi.',
    ],
    'scheduled on another open event' => [
        function ($humas) {
            $achievement = HumasFixtures::achievement(studentName: 'Fajar Ramadhan');
            app(RecordAppreciation::class)->handle($humas, HumasFixtures::event('Upacara Hari Guru', now()->addDays(11)->toDateString()), $achievement);

            return $achievement;
        },
        'Prestasi ini sudah dijadwalkan di acara Upacara Hari Guru (',
    ],
    'already appreciated on a confirmed event' => [
        function ($humas) {
            $achievement = HumasFixtures::achievement(studentName: 'Fajar Ramadhan');
            $held = HumasFixtures::event('Upacara hari ini', now()->toDateString());
            app(RecordAppreciation::class)->handle($humas, $held, $achievement);
            app(CompleteAppreciationEvent::class)->handle($humas, $held);

            return $achievement;
        },
        'Prestasi ini sudah diapresiasi di acara Upacara hari ini (',
    ],
]);

it('refuses a row that is already on this event without touching the rest of the batch', function (): void {
    $event = HumasFixtures::event();
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::achievement(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');
    app(RecordAppreciation::class)->handle($this->humas, $event, $nadia);
    Notification::fake();

    expect(fn () => app(RecordAppreciations::class)->handle($this->humas, $event, [$raka, $nadia]))
        ->toThrow(ValidationException::class, 'Prestasi ini sudah ada di daftar acara tersebut.');

    expect(Appreciation::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('refuses an empty list and more than the maximum per call', function (): void {
    $event = HumasFixtures::event();

    expect(fn () => app(RecordAppreciations::class)->handle($this->humas, $event, []))
        ->toThrow(ValidationException::class, 'Pilih minimal satu prestasi.');

    $nadia = HumasFixtures::achievement();
    $many = [$nadia];

    for ($i = 1; $i <= RecordAppreciations::MAX; $i++) {
        $many[] = HumasFixtures::teammate($nadia, 'Siswa Tim '.$i);
    }

    expect(count($many))->toBe(RecordAppreciations::MAX + 1)
        ->and(RecordAppreciations::MAX)->toBe(30);
    expect(fn () => app(RecordAppreciations::class)->handle($this->humas, $event, $many))
        ->toThrow(ValidationException::class, 'Maksimal 30 prestasi sekali masuk.');

    expect(Appreciation::query()->count())->toBe(0);
    Notification::assertNothingSent();

    // Exactly the maximum goes through, and the guru of that one team still gets one notice.
    array_pop($many);
    expect(app(RecordAppreciations::class)->handle($this->humas, $event, $many))->toHaveCount(30);
    Notification::assertSentTimes(BinaanAppreciationNotice::class, 1);
});

it('makes one row when the same achievement is named twice', function (): void {
    $nadia = HumasFixtures::achievement();

    $rows = app(RecordAppreciations::class)->handle($this->humas, HumasFixtures::event(), [$nadia, $nadia->fresh(), $nadia]);

    expect($rows)->toHaveCount(1)
        ->and(Appreciation::query()->count())->toBe(1);
    Notification::assertSentToTimes($nadia->student->user, AppreciationScheduled::class, 1);
    Notification::assertSentTimes(BinaanAppreciationNotice::class, 1);
});

it('refuses a confirmed event and an event whose date passed', function (): void {
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::achievement(studentName: 'Raka Pradipta', competitionName: 'FIKSI 2026');

    $held = HumasFixtures::event('Upacara hari ini', now()->toDateString(), '07:00');
    app(RecordAppreciation::class)->handle($this->humas, $held, HumasFixtures::achievement(studentName: 'Keisha Larasati'));
    app(CompleteAppreciationEvent::class)->handle($this->humas, $held);
    Notification::fake();

    expect(fn () => app(RecordAppreciations::class)->handle($this->humas, $held, [$nadia, $raka]))
        ->toThrow(ValidationException::class, 'Acara ini sudah dilaksanakan. Pilih acara lain.');

    $past = HumasFixtures::event('Upacara lalu', now()->subDays(3)->toDateString(), '07:00');
    expect(fn () => app(RecordAppreciations::class)->handle($this->humas, $past, [$nadia, $raka]))
        ->toThrow(ValidationException::class, 'Acara apresiasi ini sudah lewat.');

    expect(Appreciation::query()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('still takes achievements the whole day of the event, also after its start time (D-87 stays day-level)', function (): void {
    $this->travelTo(now()->setTime(15, 0));
    $event = HumasFixtures::event('Upacara pagi', now()->toDateString(), '07:00');

    expect(app(RecordAppreciations::class)->handle($this->humas, $event, [HumasFixtures::achievement()]))->toHaveCount(1);
});

it('refuses roles without appreciation.manage, and students', function (): void {
    $event = HumasFixtures::event();
    $nadia = HumasFixtures::achievement();
    $student = Student::factory()->activated()->create()->user()->first()->fresh();

    foreach ([AuthTestHelpers::staff(RoleName::Guru), AuthTestHelpers::staff(RoleName::TataUsaha), AuthTestHelpers::staff(RoleName::KepalaSekolah), $student] as $user) {
        expect(fn () => app(RecordAppreciations::class)->handle($user, $event, [$nadia]))->toThrow(AuthorizationException::class);
    }

    expect(Appreciation::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('lets Kesiswaan and Admin record a batch too', function (RoleName $role): void {
    $nadia = HumasFixtures::achievement();

    $rows = app(RecordAppreciations::class)->handle(AuthTestHelpers::staff($role), HumasFixtures::event(), [$nadia, HumasFixtures::teammate($nadia, 'Raka Pradipta')]);

    expect($rows)->toHaveCount(2);
})->with([RoleName::Kesiswaan, RoleName::Admin]);

// --- RecordAppreciation: the one-row entry (Publikasi panel) goes through the same Action ---------------------------

it('still sends one guru notice per call when a team is added one member at a time', function (): void {
    $nadia = HumasFixtures::achievement();
    $raka = HumasFixtures::teammate($nadia, 'Raka Pradipta');
    $mentor = $nadia->report->registration->mentor;
    $event = HumasFixtures::event();

    $first = app(RecordAppreciation::class)->handle($this->humas, $event, $nadia);
    app(RecordAppreciation::class)->handle($this->humas, $event, $raka);

    expect($first)->toBeInstanceOf(Appreciation::class)
        ->and($first->achievement_id)->toBe($nadia->getKey())
        ->and($first->event->getKey())->toBe($event->getKey());

    // Two clicks, two notices: only a single "Masukkan" merges them.
    Notification::assertSentToTimes($mentor, BinaanAppreciationNotice::class, 2);
    Notification::assertSentTo($mentor, BinaanAppreciationNotice::class, fn (BinaanAppreciationNotice $n): bool => $n->binaan === ['Nadia Putri Anindya (Juara II OPSI 2026)']);
});
