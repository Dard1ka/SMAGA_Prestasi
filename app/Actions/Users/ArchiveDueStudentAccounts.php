<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Accounts\RevokeUserSessions;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Admin\ArchiveRunReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Daily automatic archive (D-13, D-29, SECURITY §3m-8), run by `php artisan sth:arsip-otomatis`.
 * Students whose `archive_at` ≤ today and whose account is active or pending activation → status `archived`
 * (cannot log in), sessions + remember token revoked, audit `account.archived` (automatic, no causer). Idempotent:
 * already archived accounts are skipped, so running it twice changes nothing. A disabled account is skipped too
 * (QA9): the public pages hide a disabled student, an alumnus is named there. Achievements stay in the ranking
 * and on the public pages. Admins get one summary notice when something was archived.
 *
 * Same steps as App\Actions\Accounts\ArchiveAccount (W1-C), which needs a human actor with `user.archive`;
 * the scheduler has none, so this system Action performs them itself.
 */
final class ArchiveDueStudentAccounts
{
    public const REASON = 'Arsip otomatis: tanggal arsip tercapai.';

    public function __construct(
        private readonly RevokeUserSessions $revokeSessions,
        private readonly AdminRecipients $recipients,
    ) {}

    /** @return int number of accounts archived in this run */
    public function handle(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $archived = 0;

        Student::query()
            ->dueForArchive($today)
            ->select(['id', 'user_id', 'archive_at'])
            ->chunkById(200, function (Collection $students) use (&$archived): void {
                foreach ($students as $student) {
                    $user = DB::transaction(function () use ($student): ?User {
                        /** @var User|null $locked */
                        $locked = User::withTrashed()->whereKey($student->user_id)->lockForUpdate()->first();

                        // Only accounts that can log in (QA9): a disabled one stays disabled and hidden.
                        if ($locked === null || ! $locked->canLogIn()) {
                            return null;
                        }

                        $from = $locked->status;
                        $locked->forceFill(['status' => UserStatus::Archived])->save();

                        activity('account')
                            ->performedOn($locked)
                            ->event('account.archived')
                            ->withProperties([
                                'reason' => self::REASON,
                                'automatic' => true,
                                'from' => $from?->value,
                                'archive_at' => $student->archive_at?->toDateString(),
                            ])
                            ->log('account.archived');

                        return $locked;
                    });

                    if ($user !== null) {
                        $this->revokeSessions->handle($user);
                        $archived++;
                    }
                }
            });

        if ($archived > 0) {
            PublicAchievements::flush();
            Notification::send($this->recipients->admins(), new ArchiveRunReport($archived, $today->translatedFormat('j F Y')));
        }

        return $archived;
    }
}
