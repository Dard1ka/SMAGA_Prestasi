<?php

declare(strict_types=1);

namespace App\Actions\Trash;

use App\Actions\Accounts\RevokeUserSessions;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Student;
use App\Models\TrashEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Kelas › Lihat siswa › "Hapus" / "Hapus terpilih (N)" (the lead's decision of 5 Nov 2026, smaga_server only):
 * the students go to the trash. Per student the `students` row and the `users` row are soft-deleted (whatever the
 * account status), so the student is hidden everywhere and cannot log in; every session is ended; one TrashEntry each
 * (kind student, no parent) keeps who, when, why and until when ("purge_after", TrashEntry::purgeAfterFor()). Before that
 * moment RestoreFromTrash brings the student back unchanged; afterwards `sth:sampah:hapus-permanen` deletes the data.
 *
 * Authorised with TrashEntryPolicy::trashStudents(): `student.trash` (Kesiswaan, Admin; SV6, D-185) or `classroom.manage`
 * (Admin). Since SV6 it is called from the shared dialog App\Livewire\Admin\HapusSiswa (Admin › Kelas › Lihat siswa and
 * Status aktivasi). One transaction with the rows locked, ONE audit row `student.trashed` (log `account`) with the user
 * ids, the count, the reason and purge_after: never a name or NISN. The rows are written with the query builder, so the
 * User model's own activity log (which logs the name) stays silent.
 */
final class TrashStudents
{
    public const EVENT = 'student.trashed';

    /** One call never takes more (the student list of Admin › Kelas shows at most Kelas::STUDENTS_MAX rows). */
    public const MAX_STUDENTS = 400;

    public const MSG_CHANGED = 'Sebagian siswa sudah dihapus atau bukan siswa lagi. Muat ulang daftarnya.';

    public const MSG_NONE = 'Pilih siswa dulu.';

    /** Ids per whereIn() statement. */
    private const CHUNK = 500;

    public function __construct(private readonly RevokeUserSessions $revokeSessions) {}

    /**
     * @param  array<int|string, mixed>  $userIds  the students' account ids
     * @return list<TrashEntry> the new entries
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $userIds, string $reason): array
    {
        Gate::forUser($actor)->authorize('trashStudents', TrashEntry::class);

        $reason = TrashReason::validated($reason);
        $ids = self::ids($userIds);

        /** @var list<TrashEntry> $entries */
        $entries = DB::transaction(function () use ($actor, $ids, $reason): array {
            $users = User::query()->whereKey($ids)->lockForUpdate()->pluck('id');
            $students = Student::query()->whereIn('user_id', $ids)->lockForUpdate()->get(['id', 'user_id', 'classroom_id']);

            // A student trashed (or a row deleted) in another tab meanwhile, or an id that is no student at all.
            if ($users->count() !== count($ids) || $students->count() !== count($ids)) {
                throw ValidationException::withMessages(['students' => self::MSG_CHANGED]);
            }

            $now = CarbonImmutable::now();
            $entries = self::trashRows($students, $now, $actor, $reason, null);

            activity('account')
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties([
                    'user_ids' => $ids,
                    'count' => count($ids),
                    'reason' => $reason,
                    'purge_after' => TrashEntry::purgeAfterFor($now)->toIso8601String(),
                ])
                ->log(self::EVENT);

            return $entries;
        });

        $this->endSessions($ids);
        PublicAchievements::flush();

        return $entries;
    }

    /**
     * Soft-deletes these students (row and account) and writes one entry each, under $parent when they go with their
     * class. Runs inside the caller's transaction, on rows the caller locked.
     *
     * @param  Collection<int, Student>  $students
     * @return list<TrashEntry>
     */
    public static function trashRows(Collection $students, CarbonImmutable $now, User $actor, string $reason, ?TrashEntry $parent): array
    {
        $purgeAfter = $parent !== null ? $parent->purge_after : TrashEntry::purgeAfterFor($now);

        foreach ($students->pluck('id')->chunk(self::CHUNK) as $chunk) {
            DB::table('students')->whereIn('id', $chunk->all())->whereNull('deleted_at')->update(['deleted_at' => $now]);
        }

        foreach ($students->pluck('user_id')->chunk(self::CHUNK) as $chunk) {
            DB::table('users')->whereIn('id', $chunk->all())->whereNull('deleted_at')->update(['deleted_at' => $now]);
        }

        $entries = [];

        foreach ($students->sortBy('user_id')->values() as $student) {
            $entries[] = TrashEntry::query()->forceCreate([
                'kind' => TrashEntry::KIND_STUDENT,
                'classroom_id' => $student->classroom_id,
                'user_id' => (int) $student->user_id,
                'parent_id' => $parent?->getKey(),
                'deleted_by' => (int) $actor->getKey(),
                'deleted_at' => $now,
                'purge_after' => $purgeAfter,
                'reason' => $reason,
            ]);
        }

        return $entries;
    }

    /**
     * Ends every session of these (now trashed) accounts and rotates their remember tokens.
     *
     * @param  list<int>  $userIds
     */
    public function endSessions(array $userIds): void
    {
        foreach (array_chunk($userIds, self::CHUNK) as $chunk) {
            foreach (User::withTrashed()->whereKey($chunk)->get() as $user) {
                $this->revokeSessions->handle($user);
            }
        }
    }

    /**
     * Whole numbers, each once, sorted; refuses an empty, oversized or malformed list.
     *
     * @param  array<int|string, mixed>  $userIds
     * @return list<int>
     *
     * @throws ValidationException
     */
    private static function ids(array $userIds): array
    {
        $ids = [];

        foreach ($userIds as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw ValidationException::withMessages(['students' => self::MSG_CHANGED]);
            }

            $ids[] = (int) $id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        if ($ids === []) {
            throw ValidationException::withMessages(['students' => self::MSG_NONE]);
        }

        if (count($ids) > self::MAX_STUDENTS) {
            throw ValidationException::withMessages(['students' => 'Paling banyak '.self::MAX_STUDENTS.' siswa sekaligus.']);
        }

        return $ids;
    }
}
