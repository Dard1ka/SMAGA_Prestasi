<?php

declare(strict_types=1);

use App\Actions\Publications\CompleteAppreciationEvent;
use App\Actions\Publications\CreateAppreciationEvent;
use App\Actions\Publications\DeleteAppreciationEvent;
use App\Actions\Publications\EventWhen;
use App\Actions\Publications\RecordAppreciation;
use App\Actions\Publications\ReleaseAppreciation;
use App\Enums\AppreciationStatus;
use App\Enums\RoleName;
use App\Livewire\Humas\Apresiasi;
use App\Livewire\Humas\Jadwal;
use App\Livewire\Humas\Publikasi;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\Student;
use App\Notifications\Publications\AppreciationCancelled;
use App\Notifications\Publications\AppreciationReleased;
use App\Notifications\Publications\AppreciationScheduled;
use App\Notifications\Publications\BinaanAppreciationNotice;
use App\Notifications\Support\PlainMailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\Humas\HumasFixtures;

/*
| D-123 (lead, 2026-10-10; hand test of 2026-10-09, finding 6-1 "jangan tanggal tok, kasi jam"): an appreciation
| event has a start time. New events need one; events made before the column keep none and print the date only.
| The day-level rules of D-87 (confirm from the event's date on, add until the event's date) do not change.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->humas = HumasFixtures::humas();
});

/** A scheduled row of a fresh achievement on $event, with what the notifications read loaded. */
function em2Row(AppreciationEvent $event, string $studentName = 'Nadia Putri Anindya'): Appreciation
{
    $achievement = HumasFixtures::achievement(studentName: $studentName);
    $row = new Appreciation(['achievement_id' => $achievement->getKey(), 'appreciation_event_id' => $event->getKey()]);
    $row->forceFill(['status' => AppreciationStatus::Dijadwalkan])->save();

    return $row->fresh()->load(['event', 'achievement.student.user', 'achievement.competition', 'achievement.report']);
}

// --- EventWhen: the one place that says when an event is ----------------------------------------------------------

it('formats an event with a start time for cards, sentences and select options', function (): void {
    $event = HumasFixtures::event('Upacara Senin', '2026-10-12', '07:00');

    expect($event->heldTime())->toBe('07:00')
        ->and($event->startsAt()->format('Y-m-d H:i'))->toBe('2026-10-12 07:00')
        ->and(EventWhen::long($event))->toBe('Senin, 12 Oktober 2026, 07.00 WIB')
        ->and(EventWhen::sentence($event))->toBe('Senin, 12 Oktober 2026 pukul 07.00 WIB')
        ->and(EventWhen::short($event))->toBe('12 Okt 2026, 07.00');
});

it('prints the date only for an event made before events had a start time', function (): void {
    $event = HumasFixtures::event('Upacara Senin', '2026-10-05');

    expect($event->heldTime())->toBeNull()
        ->and($event->startsAt()->format('Y-m-d H:i'))->toBe('2026-10-05 00:00')
        ->and(EventWhen::long($event))->toBe('Senin, 5 Oktober 2026')
        ->and(EventWhen::sentence($event))->toBe('Senin, 5 Oktober 2026')
        ->and(EventWhen::short($event))->toBe('5 Okt 2026')
        ->and(EventWhen::sentence(null))->toBe('');
});

it('gives a factory event a start time', function (): void {
    expect(AppreciationEvent::factory()->create()->fresh()->heldTime())->toBe('07:00');
});

it('reads the start time of an event that only exists in memory (strict models)', function (): void {
    $event = new AppreciationEvent(['name' => 'Apel pagi', 'held_on' => '2026-10-12']);

    expect($event->heldTime())->toBeNull()
        ->and(EventWhen::long($event))->toBe('Senin, 12 Oktober 2026');
});

it('parses a start time strictly', function (string $value, string $stored): void {
    expect(EventWhen::parseTime($value))->toBe($stored);
})->with([
    'H:i' => ['07:00', '07:00:00'],
    'afternoon' => ['15:30', '15:30:00'],
    'midnight' => ['00:00', '00:00:00'],
    'last minute' => ['23:59', '23:59:00'],
    'H:i:s as a browser may send it' => ['07:15:00', '07:15:00'],
    'seconds are dropped' => ['07:15:42', '07:15:00'],
    'spaces around' => [' 09:05 ', '09:05:00'],
]);

it('refuses anything that is not a time of day', function (string $value): void {
    expect(fn () => EventWhen::parseTime($value))->toThrow(ValidationException::class, 'Isi jam acara.');
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'hour 25' => ['25:00'],
    'hour 24' => ['24:00'],
    'minute 60' => ['07:60'],
    'one digit' => ['7'],
    'one-digit hour' => ['7:00'],
    'dot instead of colon' => ['07.00'],
    'words' => ['abc'],
    'with a date' => ['2026-10-12 07:00'],
    'twelve-hour clock' => ['07:00 AM'],
]);

// --- CreateAppreciationEvent ---------------------------------------------------------------------------------------

it('stores the start time of a new event and audits it', function (RoleName $role): void {
    $actor = $role === RoleName::Humas ? $this->humas : AuthTestHelpers::staff($role);
    $date = now()->addDays(4)->toDateString();

    $event = app(CreateAppreciationEvent::class)->handle($actor, 'Upacara Senin', $date, '07:00');
    $log = Activity::query()->where('event', 'appreciation.event_created')->sole();

    expect($event->fresh()->heldTime())->toBe('07:00')
        ->and($event->heldTime())->toBe('07:00')
        ->and($event->fresh()->held_on->toDateString())->toBe($date)
        ->and($log->properties['held_on'])->toBe($date)
        ->and($log->properties['held_time'])->toBe('07:00')
        ->and($log->causer_id)->toBe($actor->getKey());
})->with([RoleName::Humas, RoleName::Kesiswaan, RoleName::Admin]);

it('needs a start time for a new event', function (string $time): void {
    try {
        app(CreateAppreciationEvent::class)->handle($this->humas, 'Upacara Senin', now()->addDays(4)->toDateString(), $time);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('held_time')
            ->and($e->errors()['held_time'][0])->toBe('Isi jam acara.')
            ->and($e->errors())->not->toHaveKeys(['name', 'held_on']);
    }

    expect(AppreciationEvent::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'appreciation.event_created')->count())->toBe(0);
})->with([
    'empty' => [''],
    'hour 25' => ['25:00'],
    'one digit' => ['7'],
    'words' => ['abc'],
]);

it('names a missing name, date and start time together', function (): void {
    try {
        app(CreateAppreciationEvent::class)->handle($this->humas, '  ', '', '');
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['name', 'held_on', 'held_time']);
    }

    expect(AppreciationEvent::query()->count())->toBe(0);
});

it('accepts a start time that already passed when the event is today, and still refuses a past date', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:30', (string) config('app.timezone')));

    // This morning's upacara is recorded with its true time (the date rule is day-level: today is fine).
    $event = app(CreateAppreciationEvent::class)->handle($this->humas, 'Upacara Senin', '2026-10-12', '07:00');

    expect($event->fresh()->heldTime())->toBe('07:00')
        ->and($event->fresh()->startsAt()->isPast())->toBeTrue();

    expect(fn () => app(CreateAppreciationEvent::class)->handle($this->humas, 'Upacara kemarin', '2026-10-11', '07:00'))
        ->toThrow(ValidationException::class, 'Tanggal acara tidak boleh lewat.');
    expect(AppreciationEvent::query()->count())->toBe(1);
});

it('still treats name and date as what makes an event a duplicate', function (): void {
    $date = now()->addWeek()->toDateString();
    app(CreateAppreciationEvent::class)->handle($this->humas, 'Upacara Senin', $date, '07:00');

    expect(fn () => app(CreateAppreciationEvent::class)->handle($this->humas, 'Upacara Senin', $date, '09:00'))
        ->toThrow(ValidationException::class, 'Acara ini sudah ada.');
    expect(AppreciationEvent::query()->count())->toBe(1);
});

it('refuses roles without appreciation.manage, and students, to create an event with a time', function (): void {
    $student = Student::factory()->activated()->create()->user()->first()->fresh();

    foreach ([AuthTestHelpers::staff(RoleName::Guru), AuthTestHelpers::staff(RoleName::TataUsaha), AuthTestHelpers::staff(RoleName::KepalaSekolah), $student] as $user) {
        expect(fn () => app(CreateAppreciationEvent::class)->handle($user, 'Upacara', now()->addDay()->toDateString(), '07:00'))
            ->toThrow(AuthorizationException::class);
    }

    expect(AppreciationEvent::query()->count())->toBe(0);
});

// --- Audit: the time travels with the date ------------------------------------------------------------------------

it('writes the start time next to the date in every appreciation audit entry', function (): void {
    $event = HumasFixtures::event('Upacara hari ini', now()->toDateString(), '07:00');
    $first = app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement());
    app(RecordAppreciation::class)->handle($this->humas, $event, HumasFixtures::achievement(studentName: 'Raka Pradipta'));
    app(ReleaseAppreciation::class)->handle($this->humas, $first);
    app(CompleteAppreciationEvent::class)->handle($this->humas, $event);

    $other = HumasFixtures::event('Acara uji coba', now()->addDays(3)->toDateString(), '13:15');
    app(DeleteAppreciationEvent::class)->handle($this->humas, $other);

    $old = HumasFixtures::event('Acara lama', now()->addDays(5)->toDateString());
    app(DeleteAppreciationEvent::class)->handle($this->humas, $old);

    $times = fn (string $name): array => Activity::query()->where('event', $name)->orderBy('id')->get()
        ->map(fn (Activity $a): ?string => $a->properties['held_time'])->all();

    expect($times('appreciation.recorded'))->toBe(['07:00', '07:00'])
        ->and($times('appreciation.released'))->toBe(['07:00'])
        ->and($times('appreciation.event_completed'))->toBe(['07:00'])
        // An event from before the column says so with null, it does not invent 00:00.
        ->and($times('appreciation.event_deleted'))->toBe(['13:15', null]);
});

// --- Notifications: the body is the only place a student or a guru learns when to show up ---------------------------

it('tells the student the time when an achievement is scheduled, released, or the event is deleted', function (): void {
    $event = HumasFixtures::event('Upacara Senin', '2026-10-12', '07:00');
    $row = em2Row($event);
    $student = $row->achievement->student;
    $user = $student->user;

    $scheduled = AppreciationScheduled::for($row);
    $released = AppreciationReleased::for($event, $row);
    $cancelled = AppreciationCancelled::for($event, [$row]);

    expect($scheduled->eventDate)->toBe('Senin, 12 Oktober 2026 pukul 07.00 WIB')
        ->and($scheduled->body())->toBe('Prestasi kamu, Juara II OPSI 2026, akan diapresiasi di Upacara Senin pada Senin, 12 Oktober 2026 pukul 07.00 WIB.')
        ->and($released->body())->toBe('Prestasi kamu, Juara II OPSI 2026, tidak jadi diapresiasi di Upacara Senin pada Senin, 12 Oktober 2026 pukul 07.00 WIB. Prestasi ini bisa dijadwalkan lagi di acara lain.')
        ->and($cancelled->body())->toBe('Acara Upacara Senin pada Senin, 12 Oktober 2026 pukul 07.00 WIB dibatalkan. Prestasi kamu, Juara II OPSI 2026, belum jadi diapresiasi di acara itu dan bisa dijadwalkan lagi di acara lain.');

    foreach ([$scheduled, $released, $cancelled] as $notification) {
        $mail = $notification->toMail($user);
        $data = $notification->toArray($user);

        expect($mail)->toBeInstanceOf(PlainMailMessage::class)
            ->and((string) $mail->render())->toContain('pukul 07.00 WIB')
            ->not->toContain($student->nis)->not->toContain($student->nisn)
            ->and($data['body'])->toContain('pukul 07.00 WIB')
            ->and(json_encode($data))->not->toContain($student->nis)->not->toContain($student->nisn)
            ->not->toContain($student->birth_date->format('Y-m-d'));
    }
});

it('tells the guru pembimbing the time in all three kinds of notice', function (): void {
    $event = HumasFixtures::event('Upacara Senin', '2026-10-12', '07:00');
    $row = em2Row($event);
    $mentor = $row->achievement->report->registration->mentor;
    $student = $row->achievement->student;

    $scheduled = BinaanAppreciationNotice::scheduled($row);
    $released = BinaanAppreciationNotice::released($event, $row);
    $cancelled = BinaanAppreciationNotice::cancelled($event, [$row]);

    expect($scheduled->body())->toBe('Nadia Putri Anindya (Juara II OPSI 2026) akan diapresiasi di Upacara Senin pada Senin, 12 Oktober 2026 pukul 07.00 WIB.')
        ->and($released->body())->toBe('Nadia Putri Anindya (Juara II OPSI 2026) tidak jadi diapresiasi di Upacara Senin pada Senin, 12 Oktober 2026 pukul 07.00 WIB. Prestasi ini bisa dijadwalkan lagi di acara lain.')
        ->and($cancelled->body())->toBe('Acara Upacara Senin pada Senin, 12 Oktober 2026 pukul 07.00 WIB dibatalkan. Nadia Putri Anindya (Juara II OPSI 2026) belum jadi diapresiasi di acara itu dan bisa dijadwalkan lagi di acara lain.');

    foreach ([$scheduled, $released, $cancelled] as $notification) {
        $mail = $notification->toMail($mentor);

        expect($mail)->toBeInstanceOf(PlainMailMessage::class)
            ->and((string) $mail->render())->toContain('pukul 07.00 WIB')
            ->not->toContain($student->nis)->not->toContain($student->nisn)
            ->and(json_encode($notification->toArray($mentor)))->not->toContain($student->nis)->not->toContain($student->nisn);
    }
});

it('keeps the date-only sentence for an event without a start time', function (): void {
    $event = HumasFixtures::event('Upacara Senin', '2026-10-05');
    $row = em2Row($event);

    expect(AppreciationScheduled::for($row)->eventDate)->toBe('Senin, 5 Oktober 2026')
        ->and(AppreciationScheduled::for($row)->body())->toBe('Prestasi kamu, Juara II OPSI 2026, akan diapresiasi di Upacara Senin pada Senin, 5 Oktober 2026.')
        ->and(AppreciationReleased::for($event, $row)->eventDate)->toBe('Senin, 5 Oktober 2026')
        ->and(AppreciationCancelled::for($event, [$row])->eventDate)->toBe('Senin, 5 Oktober 2026')
        ->and(BinaanAppreciationNotice::scheduled($row)->eventDate)->toBe('Senin, 5 Oktober 2026')
        ->and(BinaanAppreciationNotice::scheduled($row)->body())->not->toContain('pukul')->not->toContain('WIB');
});

it('keeps an event name typed by staff as plain text next to the time (D-69)', function (): void {
    $event = HumasFixtures::event('Upacara [klik](https://evil.example) Senin', '2026-10-12', '07:00');
    $row = em2Row($event);
    $user = $row->achievement->student->user;
    $mentor = $row->achievement->report->registration->mentor;

    expect((string) AppreciationScheduled::for($row)->toMail($user)->render())->toContain('pukul 07.00 WIB')->not->toContain('href="https://evil.example')
        ->and((string) BinaanAppreciationNotice::scheduled($row)->toMail($mentor)->render())->toContain('pukul 07.00 WIB')->not->toContain('href="https://evil.example');
});

// --- Screens -------------------------------------------------------------------------------------------------------

it('asks for the start time in "Buat acara" and names all three missing fields at once', function (): void {
    $date = now()->addWeek()->toDateString();

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee('Jam mulai')
        ->assertSee('Waktu Indonesia Barat (WIB).')
        // The date typed into the name was repeated in every mail: the example is the name alone.
        ->assertSeeHtml('placeholder="Upacara Senin"')
        ->call('createEvent')
        ->assertHasErrors(['name', 'heldOn', 'heldTime'])
        ->assertSee('Isi jam acara.')
        ->set('name', 'Upacara Senin')
        ->set('heldOn', $date)
        ->call('createEvent')
        ->assertHasErrors(['heldTime'])
        ->assertHasNoErrors(['name', 'heldOn'])
        ->set('heldTime', '07:00')
        ->call('createEvent')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Acara apresiasi dibuat.', tone: 'success')
        ->assertSet('name', '')
        ->assertSet('heldOn', '')
        ->assertSet('heldTime', '')
        ->assertSee(CarbonImmutable::parse($date)->locale('id')->translatedFormat('l, j F Y').', 07.00 WIB');

    expect(AppreciationEvent::query()->sole()->heldTime())->toBe('07:00');
});

it('shows the start time on the event card and in the dialogs, and the date alone for an older event', function (): void {
    $long = fn (int $days): string => now()->addDays($days)->locale('id')->translatedFormat('l, j F Y');
    $timed = HumasFixtures::event('Upacara Hari Guru', now()->addDays(8)->toDateString(), '07:00');
    app(RecordAppreciation::class)->handle($this->humas, $timed, HumasFixtures::achievement());
    HumasFixtures::event('Acara lama', now()->addDays(10)->toDateString());

    Livewire::actingAs($this->humas)
        ->test(Apresiasi::class)
        ->assertSee($long(8).', 07.00 WIB · ')
        ->assertSee($long(10).' · ')
        ->assertDontSee($long(10).',')
        // The rule for "Tandai sudah dilaksanakan" is day-level (D-87): the note names the day, not the hour.
        ->assertSee('Bisa ditandai sudah dilaksanakan mulai '.$long(8).'.')
        ->call('confirmDelete', $timed->getKey())
        ->assertViewHas('confirm', fn (array $confirm): bool => $confirm['date'] === $long(8).', 07.00 WIB');
});

it('orders events of one day by their start time on Apresiasi and on Jadwal', function (): void {
    $day = now()->addDays(3)->toDateString();
    // Created in the "wrong" order on purpose.
    HumasFixtures::event('Penutupan classmeeting', $day, '13:00');
    HumasFixtures::event('Upacara pagi', $day, '07:00');
    HumasFixtures::event('Acara tanpa jam', $day);
    HumasFixtures::event('Apel besok', now()->addDay()->toDateString(), '15:00');

    $order = ['Apel besok', 'Acara tanpa jam', 'Upacara pagi', 'Penutupan classmeeting'];

    Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->assertViewHas('upcoming', fn (array $events): bool => array_column($events, 'name') === $order);

    Livewire::actingAs($this->humas)->test(Jadwal::class)
        ->assertViewHas('events', fn (array $events): bool => array_column($events, 'name') === $order)
        ->assertSee(CarbonImmutable::parse($day)->locale('id')->translatedFormat('l, j F Y').', 07.00 WIB');
});

it('orders confirmed events of one day by their start time, latest first', function (): void {
    $day = now()->subDays(2)->toDateString();

    foreach (['Upacara pagi' => '07:00', 'Penutupan classmeeting' => '13:00'] as $name => $time) {
        HumasFixtures::event($name, $day, $time)->forceFill(['completed_at' => now()->subDay()])->save();
    }

    Livewire::actingAs($this->humas)->test(Apresiasi::class)
        ->assertViewHas('done', fn (array $events): bool => array_column($events, 'name') === ['Penutupan classmeeting', 'Upacara pagi']);
});

it('shows the time in the event choice of the publication panel and where the achievement is scheduled', function (): void {
    $publication = HumasFixtures::publication();
    $day = now()->addDays(6);
    $late = HumasFixtures::event('Penutupan classmeeting', $day->toDateString(), '13:00');
    $early = HumasFixtures::event('Upacara Senin', $day->toDateString(), '07:00');
    $short = $day->locale('id')->translatedFormat('j M Y');

    Livewire::actingAs($this->humas)
        ->test(Publikasi::class)
        ->assertViewHas('panel', fn (array $panel): bool => $panel['eventOptions'] === [
            $early->getKey() => 'Upacara Senin · '.$short.', 07.00',
            $late->getKey() => 'Penutupan classmeeting · '.$short.', 13.00',
        ])
        ->set('eventId', $early->getKey())
        ->call('recordAppreciation')
        ->assertHasNoErrors()
        ->assertSee('Sudah masuk daftar apresiasi Upacara Senin ('.$short.', 07.00)');

    expect(Appreciation::query()->sole()->achievement_id)->toBe($publication->achievement_id);
});
