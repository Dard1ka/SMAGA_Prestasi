<?php

declare(strict_types=1);

use App\Actions\Trash\RestoreFromTrash;
use App\Actions\Trash\TrashStudents;
use App\Enums\UserStatus;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use App\Notifications\Channels\BellChannel;
use App\Notifications\Reports\ReportReminder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * smaga_server, the trash of 5 Nov 2026: a trashed student (its students row AND users row soft-deleted, the status left
 * as it was) must hear nothing more from the app until "Pulihkan". The account keeps status active or pending, so
 * User::canLogIn() and User::routeNotificationForMail() used to see a live account, and a queued notification
 * restores its notifiable without the SoftDeletes scope (SerializesModels → newQueryForRestoration()), so a mail and a
 * bell row still went out to a student in the trash. Fictional data only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Jakarta'));
    $this->admin = A::admin(['name' => 'Admin Sekolah Contoh']);
    $this->classroom = Classroom::factory()->create(['name' => 'X-4', 'level' => 10]);

    // Every mail that leaves the mailer, by recipient (the channels run for real; nothing is faked).
    $this->mailedTo = [];
    Event::listen(MessageSending::class, function (MessageSending $event): void {
        foreach ($event->message->getTo() as $address) {
            $this->mailedTo[] = $address->getAddress();
        }
    });
});

/** An activated student with a verified email, of $classroom, with $status. */
function trashedNoticeStudent(Classroom $classroom, string $email, UserStatus $status = UserStatus::Active): Student
{
    $student = Student::factory()->activated()->create(['classroom_id' => $classroom->getKey()]);
    $student->user->forceFill(['email' => $email, 'email_verified_at' => now(), 'status' => $status])->saveQuietly();

    return $student->fresh();
}

/** Puts these students in the trash through the Admin's Action and returns the trashed accounts. */
function trashedNoticeTrash(User $admin, Student ...$students): array
{
    $ids = array_map(fn (Student $s): int => (int) $s->user_id, $students);
    app(TrashStudents::class)->handle($admin, $ids, 'Data ganda dari import.');

    return array_map(fn (int $id): User => User::withTrashed()->findOrFail($id), $ids);
}

/** The bell rows of an account. */
function trashedNoticeBellRows(int|string $userId): int
{
    return DB::table('notifications')->where('notifiable_id', $userId)->count();
}

function trashedNoticeReminder(): ReportReminder
{
    return new ReportReminder('01JC0000000000000000000000', 'Olimpiade Sains Contoh', 3);
}

it('closes a trashed account to logging in whatever its status says, and opens it again after Pulihkan', function (UserStatus $status): void {
    $student = trashedNoticeStudent($this->classroom, 'nadia.anindya@example.test', $status);
    expect($student->user->canLogIn())->toBeTrue();

    [$user] = trashedNoticeTrash($this->admin, $student);

    expect($user->trashed())->toBeTrue()
        ->and($user->status)->toBe($status)
        ->and($user->canLogIn())->toBeFalse();

    app(RestoreFromTrash::class)->handle($this->admin, TrashEntry::query()->where('user_id', $user->getKey())->firstOrFail());

    expect(User::query()->findOrFail($user->getKey())->canLogIn())->toBeTrue();
})->with([
    'active' => [UserStatus::Active],
    'pending activation' => [UserStatus::PendingActivation],
]);

it('gives a trashed account no mail address, and gives it back after Pulihkan', function (): void {
    $student = trashedNoticeStudent($this->classroom, 'nadia.anindya@example.test');
    expect($student->user->routeNotificationForMail(trashedNoticeReminder()))->toBe('nadia.anindya@example.test');

    [$user] = trashedNoticeTrash($this->admin, $student);

    expect($user->routeNotificationForMail(trashedNoticeReminder()))->toBeNull();

    app(RestoreFromTrash::class)->handle($this->admin, TrashEntry::query()->where('user_id', $user->getKey())->firstOrFail());

    expect(User::query()->findOrFail($user->getKey())->routeNotificationForMail(trashedNoticeReminder()))
        ->toBe('nadia.anindya@example.test');
});

it('sends neither the mail nor the bell row of a notice queued before the student went to the trash', function (): void {
    $student = trashedNoticeStudent($this->classroom, 'nadia.anindya@example.test');

    Queue::fake();
    $student->user->notify(trashedNoticeReminder());
    $jobs = Queue::pushed(SendQueuedNotifications::class);
    expect($jobs)->toHaveCount(2); // one job per channel: mail and database

    trashedNoticeTrash($this->admin, $student);

    // The worker takes the jobs after the trash: unserializing restores the account WITHOUT the SoftDeletes scope.
    foreach ($jobs as $job) {
        $restored = unserialize(serialize($job));
        expect($restored->notifiables->first()->trashed())->toBeTrue();
        $restored->handle(app(ChannelManager::class));
    }

    expect($this->mailedTo)->toBe([])
        ->and(trashedNoticeBellRows($student->user_id))->toBe(0);
});

it('still sends a queued notice to a student who is not in the trash (control)', function (): void {
    $student = trashedNoticeStudent($this->classroom, 'nadia.anindya@example.test');

    Queue::fake();
    $student->user->notify(trashedNoticeReminder());

    foreach (Queue::pushed(SendQueuedNotifications::class) as $job) {
        unserialize(serialize($job))->handle(app(ChannelManager::class));
    }

    expect($this->mailedTo)->toBe(['nadia.anindya@example.test'])
        ->and(trashedNoticeBellRows($student->user_id))->toBe(1);
});

it('skips only the trashed account when one notice goes to several people at once', function (): void {
    $trashed = trashedNoticeStudent($this->classroom, 'nadia.anindya@example.test');
    $live = trashedNoticeStudent($this->classroom, 'raka.pratama@example.test');
    [$trashedUser] = trashedNoticeTrash($this->admin, $trashed);

    Notification::sendNow([$trashedUser, $live->user], trashedNoticeReminder());

    expect($this->mailedTo)->toBe(['raka.pratama@example.test'])
        ->and(trashedNoticeBellRows($trashedUser->getKey()))->toBe(0)
        ->and(trashedNoticeBellRows($live->user_id))->toBe(1);
});

it('writes no bell row for a trashed account through the database channel itself', function (): void {
    [$user] = trashedNoticeTrash($this->admin, trashedNoticeStudent($this->classroom, 'nadia.anindya@example.test'));
    $notice = trashedNoticeReminder();
    $notice->id = (string) Str::uuid(); // NotificationSender gives every notice its id; here the channel is called alone

    expect(app(BellChannel::class)->send($user, $notice))->toBeNull()
        ->and(trashedNoticeBellRows($user->getKey()))->toBe(0);
});
