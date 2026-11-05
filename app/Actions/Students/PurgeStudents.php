<?php

declare(strict_types=1);

namespace App\Actions\Students;

use App\Enums\FilePurpose;
use App\Enums\MemberRole;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Setting;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * smaga_server, the lead's decision of 5 Nov 2026 (trash "Sampah", Admin › Kelas): deletes a SET of students for good,
 * called by `sth:sampah:hapus-permanen` for the students whose 7 days in the trash are over
 * (`app(PurgeStudents::class)->handle($studentIds)`). The sibling of DeleteAllStudents (D-178), which deletes ALL
 * students: the same tables, files rules and account rows, reused through its constants, but here other students stay,
 * so rows shared with them (team registrations) are split instead of emptied.
 *
 * Deleted, per student of the set (soft-deleted `students` and `users` rows included, the query builder sees them):
 *  - everything that belongs only to them: their achievements with the appreciations and publications on them, talent
 *    applications (soft-deleted too), talent profiles with their files, their `registration_members` rows, OTP codes,
 *    sessions, bell rows, role and permission rows, email notices, password-reset rows, passkeys, imports and staff
 *    profile, the `students` row and the `users` row (a hard delete);
 *  - a registration whose every person (every member row, confirmed or not, the leader and every student with an
 *    achievement on its report) is in the set: with its letters (revoked ones too), report, report files, reminders and
 *    members;
 *  - the files of those rows and the student evidence the purged accounts own that nothing points at any more, each
 *    only when no row that stays still points at it (a lomba's juknis or cover, another report, an import, the
 *    signature setting) and never a signature image. Their bytes go after the transaction committed.
 *
 * Kept and changed:
 *  - a mixed registration (people inside and outside the set) stays; only the set's member rows go. When its leader
 *    (`registrations.leader_student_id`) is in the set, the leadership passes to the first remaining member: confirmed
 *    members first, then members who did not answer, declined ones last, each group by member `created_at`, then id;
 *    that member row becomes `ketua`. `reports.submitted_by` and the owner of the report's files that name a purged
 *    account move to the (new) leader's account. The remaining members' achievements are untouched;
 *  - a lomba a purged student proposed moves to the first active Admin (by id), as D-178; other nullable user columns
 *    of kept rows that name a purged account become null (USER_REFERENCES_NULLED).
 *
 * Refused, with nothing written (a DomainException whose message holds counts only): a required user column of a kept
 * history row names a purged account (USER_REFERENCES_BLOCKING), a lomba a purged student proposed but no active Admin,
 * or a kept registration whose purged leader has no remaining member row to take over.
 *
 * ONE transaction, query builder only (no model events, so no row-by-row audit), NO audit row here (the command writes
 * one). Returns counts only: never a name, NISN or email. Idempotent: ids already gone are skipped, a second call finds
 * nothing and writes nothing. The public pages' cache is flushed after the commit.
 */
final class PurgeStudents
{
    /** Ids per whereIn() statement. */
    public const CHUNK = DeleteAllStudents::CHUNK;

    /** The keys of the returned counts, in this order. */
    public const COUNT_KEYS = [
        'students',
        'users',
        'registrations_deleted',
        'registrations_kept',
        'registrations_new_leader',
        'members',
        'letters',
        'reports',
        'achievements',
        'publications',
        'appreciations',
        'talent_applications',
        'talent_profiles',
        'files',
        'competitions_to_admin',
        'disk_missing',
        'disk_failed',
    ];

    /** Every column that points at a `students` row: deleted with the student, or moved (a mixed team's leader). */
    public const STUDENT_REFERENCES = [
        ['registrations', 'leader_student_id'],
        ['registration_members', 'student_id'],
        ['achievements', 'student_id'],
        ['talent_applications', 'student_id'],
        ['talent_profiles', 'student_id'],
    ];

    /** Rows deleted with a registration whose every person is purged, children first. */
    public const REGISTRATION_ROWS_DELETED = [
        ['registration_reminders', 'registration_id'],
        ['letters', 'registration_id'],
        ['reports', 'registration_id'],
        ['registration_members', 'registration_id'],
    ];

    /** Rows deleted with their deleted parent (report, achievement, talent profile). */
    public const CHILD_ROWS_DELETED = [
        ['report_files', 'report_id'],
        ['achievements', 'report_id'],
        ['publications', 'achievement_id'],
        ['appreciations', 'achievement_id'],
        ['talent_profile_files', 'talent_profile_id'],
    ];

    /** A replacement letter points at the letter it replaced: unlinked when that letter goes. */
    public const LETTER_REFERENCES_NULLED = [
        ['letters', 'replaces_letter_id'],
    ];

    /** File columns of deleted rows: those files go unless something that stays still points at them. */
    public const DELETED_FILE_REFERENCES = DeleteAllStudents::DELETED_FILE_REFERENCES;

    /** File columns of deleted rows whose files always stay (the signature image of a letter). */
    public const KEPT_FILES_OF_DELETED_ROWS = DeleteAllStudents::KEPT_FILES_OF_DELETED_ROWS;

    /** File columns of rows that stay: a file named here is never deleted (the signature setting is checked too). */
    public const KEPT_FILE_REFERENCES = DeleteAllStudents::KEPT_FILE_REFERENCES;

    /** Required user column of a kept registration's report: moves to the (new) leader's account. */
    public const USER_REFERENCES_TO_LEADER = [
        ['reports', 'submitted_by'],
    ];

    /** The lomba a purged account proposed: the first active Admin becomes the submitter. */
    public const USER_REFERENCES_MOVED = DeleteAllStudents::USER_REFERENCES_MOVED;

    /**
     * Nullable user columns of rows that stay: emptied where they name a purged account. Next to D-178's list the rows
     * of other students' registrations, letters, reports, talent choices and publications stay here too.
     */
    public const USER_REFERENCES_NULLED = [
        ...DeleteAllStudents::USER_REFERENCES_NULLED,
        ['registrations', 'mentor_id'],
        ['registrations', 'principal_decision_by'],
        ['letters', 'generated_by'],
        ['letters', 'revoked_by'],
        ['reports', 'verified_by'],
        ['talent_applications', 'mentor_id'],
        ['publications', 'created_by'],
    ];

    /** Rows of a purged account that go with it, in this order (`students` last: `users` needs it gone). */
    public const USER_ROWS_DELETED = DeleteAllStudents::USER_ROWS_DELETED;

    /** Required user columns of history rows that stay: a purged account here refuses the run. */
    public const USER_REFERENCES_BLOCKING = DeleteAllStudents::USER_REFERENCES_BLOCKING;

    /**
     * Deletes these students (students.id) for good in one transaction, then the bytes of the deleted files.
     *
     * @param  array<int|string, mixed>  $studentIds
     * @return array<string, int> COUNT_KEYS => count
     *
     * @throws DomainException when the run is refused; nothing was written then
     */
    public function handle(array $studentIds): array
    {
        $ids = self::normalize($studentIds);

        if ($ids === []) {
            return self::emptyCounts();
        }

        /** @var array{counts: array<string, int>, bytes: list<array{disk: string, path: string}>|null} $outcome */
        $outcome = DB::transaction(fn (): array => $this->purge($ids));

        return $this->afterCommit($outcome);
    }

    /**
     * SV6, D-186 ("Hapus permanen" of one Sampah entry, App\Actions\Trash\PurgeFromTrash): the purge of handle() with the
     * caller's own checks and writes in the SAME transaction. $before($ids) runs first (lock and re-check; a throw leaves
     * everything as it was), $after($counts) runs after the deletes (close the entries, audit; a throw rolls the deletes
     * back). The file bytes still go only after the commit, then the public cache is flushed.
     *
     * @param  array<int|string, mixed>  $studentIds
     * @param  Closure(list<string>): void  $before
     * @param  Closure(array<string, int>): void  $after
     * @return array<string, int> COUNT_KEYS => count
     *
     * @throws DomainException when the purge is refused; nothing was written then
     */
    public function handleWithin(array $studentIds, Closure $before, Closure $after): array
    {
        $ids = self::normalize($studentIds);

        /** @var array{counts: array<string, int>, bytes: list<array{disk: string, path: string}>|null} $outcome */
        $outcome = DB::transaction(function () use ($ids, $before, $after): array {
            $before($ids);
            $outcome = $ids === [] ? ['counts' => self::emptyCounts(), 'bytes' => null] : $this->purge($ids);
            $after($outcome['counts']);

            return $outcome;
        });

        return $this->afterCommit($outcome);
    }

    /**
     * SV6, D-186: what handle() would do with these students, writing nothing: the refusal sentence (null = it would go
     * through) and the counts. `files` counts the files no row that stays points at; `disk_*` stay 0.
     *
     * @param  array<int|string, mixed>  $studentIds
     * @return array{refusal: ?string, counts: array<string, int>}
     */
    public function preview(array $studentIds): array
    {
        $counts = self::emptyCounts();
        $ids = self::normalize($studentIds);

        if ($ids === []) {
            return ['refusal' => null, 'counts' => $counts];
        }

        try {
            $read = $this->read($ids);
        } catch (DomainException $e) {
            return ['refusal' => $e->getMessage(), 'counts' => $counts];
        }

        if ($read === null) {
            return ['refusal' => null, 'counts' => $counts];
        }

        $counts['students'] = count($read['studentIds']);
        $counts['users'] = count($read['users']);
        $counts['registrations_deleted'] = count($read['full']);
        $counts['registrations_kept'] = count($read['kept']);
        $counts['registrations_new_leader'] = count(array_filter($read['kept'], fn (array $kept): bool => $kept['member_id'] !== null));
        $counts['members'] = $this->countIn('registration_members', 'student_id', $read['studentIds']);
        $counts['letters'] = count($read['letters']);
        $counts['reports'] = count($read['reports']);
        $counts['achievements'] = count($read['achievements']);
        $counts['publications'] = $this->countIn('publications', 'achievement_id', $read['achievements']);
        $counts['appreciations'] = $this->countIn('appreciations', 'achievement_id', $read['achievements']);
        $counts['talent_applications'] = count($read['talentApplications']);
        $counts['talent_profiles'] = count($read['talentProfiles']);
        $counts['files'] = count($this->unreferenced($read['candidates'], [
            'letters' => ['id', $read['letters']],
            'report_files' => ['report_id', $read['reports']],
            'talent_applications' => ['id', $read['talentApplications']],
            'talent_profile_files' => ['talent_profile_id', $read['talentProfiles']],
            'publications' => ['achievement_id', $read['achievements']],
        ]));

        foreach (self::USER_REFERENCES_MOVED as [$table, $column]) {
            $counts['competitions_to_admin'] += $this->countIn($table, $column, $read['users']);
        }

        return ['refusal' => null, 'counts' => $counts];
    }

    /**
     * Deletes the file bytes of a committed purge and flushes the public cache.
     *
     * @param  array{counts: array<string, int>, bytes: list<array{disk: string, path: string}>|null}  $outcome
     * @return array<string, int>
     */
    private function afterCommit(array $outcome): array
    {
        $counts = $outcome['counts'];

        if ($outcome['bytes'] === null) {
            return $counts;
        }

        // After the commit: a rolled-back run never loses a file it still points at.
        foreach ($outcome['bytes'] as $file) {
            try {
                $disk = Storage::disk($file['disk']);

                if (! $disk->exists($file['path'])) {
                    $counts['disk_missing']++;

                    continue;
                }

                if (! $disk->delete($file['path'])) {
                    $counts['disk_failed']++;
                }
            } catch (Throwable) {
                $counts['disk_failed']++;
            }
        }

        PublicAchievements::flush();

        return $counts;
    }

    /**
     * @param  list<string>  $ids
     * @return array{counts: array<string, int>, bytes: list<array{disk: string, path: string}>|null}
     */
    private function purge(array $ids): array
    {
        $counts = self::emptyCounts();
        $read = $this->read($ids);

        if ($read === null) {
            return ['counts' => $counts, 'bytes' => null];
        }

        [
            'studentIds' => $studentIds, 'users' => $users, 'full' => $fullRegistrations, 'kept' => $keptRegistrations,
            'adminId' => $adminId, 'letters' => $letters, 'reports' => $reports, 'achievements' => $achievements,
            'talentApplications' => $talentApplications, 'talentProfiles' => $talentProfiles, 'candidates' => $candidates,
        ] = $read;

        // 1. Mixed teams: the leadership passes, the set's member rows go, the report moves to the leader.
        foreach ($keptRegistrations as $registrationId => $kept) {
            if ($kept['member_id'] !== null) {
                DB::table('registrations')->where('id', $registrationId)->update(['leader_student_id' => $kept['leader']]);
                DB::table('registration_members')->where('id', $kept['member_id'])->update(['role' => MemberRole::Ketua->value]);
                $counts['registrations_new_leader']++;
            }

            $this->moveToLeader((string) $registrationId, $kept['leader'], $users);
            $counts['registrations_kept']++;
        }

        foreach (array_chunk($studentIds, self::CHUNK) as $chunk) {
            $counts['members'] += DB::table('registration_members')->whereIn('student_id', $chunk)->delete();
        }

        // 2. The achievements of the set (and those on a deleted report) with what hangs on them.
        foreach (array_chunk($achievements, self::CHUNK) as $chunk) {
            $counts['appreciations'] += DB::table('appreciations')->whereIn('achievement_id', $chunk)->delete();
            $counts['publications'] += DB::table('publications')->whereIn('achievement_id', $chunk)->delete();
            $counts['achievements'] += DB::table('achievements')->whereIn('id', $chunk)->delete();
        }

        // 3. Registrations whose every person is purged, children first.
        foreach (array_chunk($letters, self::CHUNK) as $chunk) {
            foreach (self::LETTER_REFERENCES_NULLED as [$table, $column]) {
                DB::table($table)->whereIn($column, $chunk)->update([$column => null]);
            }
        }

        foreach (array_chunk($reports, self::CHUNK) as $chunk) {
            DB::table('report_files')->whereIn('report_id', $chunk)->delete();
        }

        $countedAs = ['letters' => 'letters', 'reports' => 'reports', 'registration_members' => 'members'];

        foreach (array_chunk($fullRegistrations, self::CHUNK) as $chunk) {
            foreach (self::REGISTRATION_ROWS_DELETED as [$table, $column]) {
                $deleted = DB::table($table)->whereIn($column, $chunk)->delete();

                if (isset($countedAs[$table])) {
                    $counts[$countedAs[$table]] += $deleted;
                }
            }

            $counts['registrations_deleted'] += DB::table('registrations')->whereIn('id', $chunk)->delete();
        }

        // 4. Talent choices and profiles.
        foreach (array_chunk($talentProfiles, self::CHUNK) as $chunk) {
            DB::table('talent_profile_files')->whereIn('talent_profile_id', $chunk)->delete();
            $counts['talent_profiles'] += DB::table('talent_profiles')->whereIn('id', $chunk)->delete();
        }

        foreach (array_chunk($talentApplications, self::CHUNK) as $chunk) {
            $counts['talent_applications'] += DB::table('talent_applications')->whereIn('id', $chunk)->delete();
        }

        // 5. Rows that stay but name a purged account.
        foreach (array_chunk($users, self::CHUNK) as $chunk) {
            foreach (self::USER_REFERENCES_MOVED as [$table, $column]) {
                $counts['competitions_to_admin'] += DB::table($table)->whereIn($column, $chunk)->update([$column => $adminId]);
            }

            foreach (self::USER_REFERENCES_NULLED as [$table, $column]) {
                DB::table($table)->whereIn($column, $chunk)->update([$column => null]);
            }
        }

        // 6. The files nothing that stays points at (checked after the deletes above).
        $bytes = [];

        foreach (array_chunk($this->unreferenced($candidates), self::CHUNK) as $chunk) {
            foreach (DB::table('files')->whereIn('id', $chunk)->get(['disk', 'path']) as $file) {
                $bytes[] = ['disk' => (string) $file->disk, 'path' => (string) $file->path];
            }

            $counts['files'] += DB::table('files')->whereIn('id', $chunk)->delete();
        }

        // 7. The accounts.
        $morph = (new User)->getMorphClass();
        $tables = (array) config('permission.table_names');
        $modelKey = (string) (config('permission.column_names.model_morph_key') ?? 'model_id');

        foreach (array_chunk($users, self::CHUNK) as $chunk) {
            DB::table('notifications')->where('notifiable_type', $morph)->whereIn('notifiable_id', $chunk)->delete();
            DB::table($tables['model_has_roles'] ?? 'model_has_roles')->where('model_type', $morph)->whereIn($modelKey, $chunk)->delete();
            DB::table($tables['model_has_permissions'] ?? 'model_has_permissions')->where('model_type', $morph)->whereIn($modelKey, $chunk)->delete();

            $emails = DB::table('users')->whereIn('id', $chunk)->whereNotNull('email')->pluck('email')->all();

            if ($emails !== []) {
                DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
            }

            foreach (self::USER_ROWS_DELETED as [$table, $column]) {
                $deleted = DB::table($table)->whereIn($column, $chunk)->delete();

                if ($table === 'students') {
                    $counts['students'] += $deleted;
                }
            }

            $counts['users'] += DB::table('users')->whereIn('id', $chunk)->delete();
        }

        return ['counts' => $counts, 'bytes' => $bytes];
    }

    /**
     * The read part of purge() (SV6, D-186: shared with preview()): the students that still exist, the refusal check and
     * every row the deletes will touch. Null = none of the ids exists any more. Writes nothing.
     *
     * @param  list<string>  $ids
     * @return array{studentIds: list<string>, users: list<int>, full: list<string>, kept: array<string, array{leader: ?string, member_id: ?string}>, adminId: ?int, letters: list<string>, reports: list<string>, achievements: list<string>, talentApplications: list<string>, talentProfiles: list<string>, candidates: list<string>}|null
     *
     * @throws DomainException
     */
    private function read(array $ids): ?array
    {
        // students.id => users.id of the students that still exist (trashed ones included).
        $students = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (DB::table('students')->whereIn('id', $chunk)->lockForUpdate()->get(['id', 'user_id']) as $row) {
                $students[(string) $row->id] = (int) $row->user_id;
            }
        }

        if ($students === []) {
            return null;
        }

        $studentIds = array_map(strval(...), array_keys($students));
        sort($studentIds);
        $users = array_values(array_unique(array_values($students)));
        sort($users);
        $inSet = array_fill_keys($studentIds, true);

        [$full, $kept] = $this->splitRegistrations($studentIds, $inSet);
        $adminId = $this->refuseOrAdmin($users, $kept);

        // Everything the deleted rows point at is read before the first write.
        $letters = $this->pluckIn('letters', 'id', 'registration_id', $full);
        $reports = $this->pluckIn('reports', 'id', 'registration_id', $full);
        $achievements = array_values(array_unique([
            ...$this->pluckIn('achievements', 'id', 'student_id', $studentIds),
            ...$this->pluckIn('achievements', 'id', 'report_id', $reports),
        ]));
        $talentApplications = $this->pluckIn('talent_applications', 'id', 'student_id', $studentIds);
        $talentProfiles = $this->pluckIn('talent_profiles', 'id', 'student_id', $studentIds);
        $candidates = $this->fileCandidates($users, $letters, $reports, $achievements, $talentApplications, $talentProfiles);

        return [
            'studentIds' => $studentIds,
            'users' => $users,
            'full' => $full,
            'kept' => $kept,
            'adminId' => $adminId,
            'letters' => $letters,
            'reports' => $reports,
            'achievements' => $achievements,
            'talentApplications' => $talentApplications,
            'talentProfiles' => $talentProfiles,
            'candidates' => $candidates,
        ];
    }

    /**
     * The registrations the set takes part in, split into the ones that go as a whole and the ones that stay. A kept
     * registration names its leader afterwards and, when the leadership passes, the member row that takes it over
     * (null `leader` = its purged leader has nobody left to take over: the run is refused).
     *
     * @param  list<string>  $studentIds
     * @param  array<string, true>  $inSet
     * @return array{0: list<string>, 1: array<string, array{leader: ?string, member_id: ?string}>}
     */
    private function splitRegistrations(array $studentIds, array $inSet): array
    {
        $registrationIds = array_values(array_unique([
            ...$this->pluckIn('registration_members', 'registration_id', 'student_id', $studentIds),
            ...$this->pluckIn('registrations', 'id', 'leader_student_id', $studentIds),
        ]));
        sort($registrationIds);

        $leaders = [];
        $members = [];
        $people = [];

        foreach (array_chunk($registrationIds, self::CHUNK) as $chunk) {
            foreach (DB::table('registrations')->whereIn('id', $chunk)->lockForUpdate()->get(['id', 'leader_student_id']) as $row) {
                $leaders[(string) $row->id] = (string) $row->leader_student_id;
                $people[(string) $row->id][(string) $row->leader_student_id] = true;
            }

            $rows = DB::table('registration_members')
                ->whereIn('registration_id', $chunk)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'registration_id', 'student_id', 'confirmed_at', 'declined_at']);

            foreach ($rows as $row) {
                $members[(string) $row->registration_id][] = $row;
                $people[(string) $row->registration_id][(string) $row->student_id] = true;
            }

            // A student with an achievement on the report counts as a person of the registration too.
            $achievers = DB::table('achievements')
                ->join('reports', 'reports.id', '=', 'achievements.report_id')
                ->whereIn('reports.registration_id', $chunk)
                ->get(['reports.registration_id', 'achievements.student_id']);

            foreach ($achievers as $row) {
                $people[(string) $row->registration_id][(string) $row->student_id] = true;
            }
        }

        $full = [];
        $kept = [];

        foreach ($leaders as $registrationId => $leader) {
            $outside = array_diff_key($people[$registrationId] ?? [], $inSet);

            if ($outside === []) {
                $full[] = (string) $registrationId;

                continue;
            }

            if (! isset($inSet[$leader])) {
                $kept[(string) $registrationId] = ['leader' => $leader, 'member_id' => null];

                continue;
            }

            $next = $this->nextLeader($members[$registrationId] ?? [], $inSet);
            $kept[(string) $registrationId] = [
                'leader' => $next === null ? null : (string) $next->student_id,
                'member_id' => $next === null ? null : (string) $next->id,
            ];
        }

        return [$full, $kept];
    }

    /**
     * The first remaining member: confirmed first, then not answered, declined last; each by created_at, then id
     * (the rows come ordered so).
     *
     * @param  list<object>  $members
     * @param  array<string, true>  $inSet
     */
    private function nextLeader(array $members, array $inSet): ?object
    {
        $rank = fn (object $member): int => match (true) {
            $member->declined_at !== null => 2,
            $member->confirmed_at !== null => 0,
            default => 1,
        };

        $remaining = array_values(array_filter($members, fn (object $member): bool => ! isset($inSet[(string) $member->student_id])));

        // usort is stable: the created_at / id order of the query stays inside each rank.
        usort($remaining, fn (object $a, object $b): int => $rank($a) <=> $rank($b));

        return $remaining[0] ?? null;
    }

    /**
     * Refuses the run (nothing written yet) or returns the Admin that takes over the purged students' lomba.
     *
     * @param  list<int>  $users
     * @param  array<string, array{leader: ?string, member_id: ?string}>  $keptRegistrations
     *
     * @throws DomainException
     */
    private function refuseOrAdmin(array $users, array $keptRegistrations): ?int
    {
        $blocking = 0;

        foreach (self::USER_REFERENCES_BLOCKING as [$table, $column]) {
            $blocking += $this->countIn($table, $column, $users);
        }

        if ($blocking > 0) {
            throw new DomainException("{$blocking} baris riwayat penggantian Kepala Sekolah atau perubahan peran menunjuk akun siswa yang dihapus. Tidak ada yang dihapus.");
        }

        $leaderless = count(array_filter($keptRegistrations, fn (array $kept): bool => $kept['leader'] === null));

        if ($leaderless > 0) {
            throw new DomainException("{$leaderless} pendaftaran kehilangan ketua tanpa anggota lain yang bisa menggantikannya. Tidak ada yang dihapus.");
        }

        $proposals = 0;

        foreach (self::USER_REFERENCES_MOVED as [$table, $column]) {
            $proposals += $this->countIn($table, $column, $users);
        }

        if ($proposals === 0) {
            return null;
        }

        $adminId = $this->adminId($users);

        if ($adminId === null) {
            throw new DomainException("Tidak ada akun Admin aktif yang bisa menjadi pengusul {$proposals} lomba usulan siswa. Tidak ada yang dihapus.");
        }

        return $adminId;
    }

    /**
     * A kept registration's report and its files that name a purged account move to the leader's account.
     *
     * @param  list<int>  $users
     */
    private function moveToLeader(string $registrationId, ?string $leaderStudentId, array $users): void
    {
        $leaderUser = DB::table('students')->where('id', $leaderStudentId)->value('user_id');

        if ($leaderUser === null) {
            return;
        }

        $reports = DB::table('reports')->where('registration_id', $registrationId)->pluck('id')->map(strval(...))->all();

        foreach (array_chunk($users, self::CHUNK) as $chunk) {
            foreach (self::USER_REFERENCES_TO_LEADER as [$table, $column]) {
                DB::table($table)->where('registration_id', $registrationId)->whereIn($column, $chunk)->update([$column => (int) $leaderUser]);
            }

            foreach (array_chunk($reports, self::CHUNK) as $reportChunk) {
                $files = DB::table('report_files')->whereIn('report_id', $reportChunk)->pluck('file_id')->map(strval(...))->all();

                foreach (array_chunk($files, self::CHUNK) as $fileChunk) {
                    DB::table('files')->whereIn('id', $fileChunk)->whereIn('owner_user_id', $chunk)->update(['owner_user_id' => (int) $leaderUser]);
                }
            }
        }
    }

    /**
     * Files the deleted rows point at plus the student evidence the purged accounts own; whether they really go is
     * decided after the deletes (unreferenced()).
     *
     * @param  list<int>  $users
     * @param  list<string>  $letters
     * @param  list<string>  $reports
     * @param  list<string>  $achievements
     * @param  list<string>  $talentApplications
     * @param  list<string>  $talentProfiles
     * @return list<string>
     */
    private function fileCandidates(array $users, array $letters, array $reports, array $achievements, array $talentApplications, array $talentProfiles): array
    {
        $rows = [
            'letters' => ['id', $letters],
            'report_files' => ['report_id', $reports],
            'talent_applications' => ['id', $talentApplications],
            'talent_profile_files' => ['talent_profile_id', $talentProfiles],
            'publications' => ['achievement_id', $achievements],
        ];

        $candidates = [];

        foreach (self::DELETED_FILE_REFERENCES as [$table, $column]) {
            [$key, $ids] = $rows[$table] ?? [null, []];

            if ($key === null) {
                continue;
            }

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                foreach (DB::table($table)->whereIn($key, $chunk)->whereNotNull($column)->pluck($column) as $id) {
                    $candidates[(string) $id] = true;
                }
            }
        }

        $purposes = array_map(fn (FilePurpose $purpose): string => $purpose->value, DeleteAllStudents::OWNED_PURPOSES);

        foreach (array_chunk($users, self::CHUNK) as $chunk) {
            foreach (DB::table('files')->whereIn('owner_user_id', $chunk)->whereIn('purpose', $purposes)->pluck('id') as $id) {
                $candidates[(string) $id] = true;
            }
        }

        $ids = array_map(strval(...), array_keys($candidates));
        sort($ids);

        return $ids;
    }

    /**
     * The candidates no remaining row points at, minus the signature setting and every signature image.
     *
     * $deleting (preview(), nothing deleted yet) = table => [key column, keys of the rows the purge would delete]: their
     * references are ignored, as they are gone when purge() asks.
     *
     * @param  list<string>  $candidates
     * @param  array<string, array{0: string, 1: list<string>}>  $deleting
     * @return list<string>
     */
    private function unreferenced(array $candidates, array $deleting = []): array
    {
        $left = array_fill_keys($candidates, true);
        $columns = [...self::DELETED_FILE_REFERENCES, ...self::KEPT_FILES_OF_DELETED_ROWS, ...self::KEPT_FILE_REFERENCES];
        $gone = array_map(fn (array $rows): array => array_fill_keys($rows[1], true), $deleting);

        foreach (array_chunk($candidates, self::CHUNK) as $chunk) {
            foreach ($columns as [$table, $column]) {
                if (isset($deleting[$table])) {
                    $key = $deleting[$table][0];

                    foreach (DB::table($table)->whereIn($column, $chunk)->get([$key, $column]) as $row) {
                        if (! isset($gone[$table][(string) $row->{$key}])) {
                            unset($left[(string) $row->{$column}]);
                        }
                    }

                    continue;
                }

                foreach (DB::table($table)->whereIn($column, $chunk)->distinct()->pluck($column) as $id) {
                    unset($left[(string) $id]);
                }
            }
        }

        $signature = Setting::get(SettingKey::SignatureFileId);

        if (is_string($signature) && $signature !== '') {
            unset($left[$signature]);
        }

        $files = [];

        foreach (array_chunk(array_map(strval(...), array_keys($left)), self::CHUNK) as $chunk) {
            $ids = DB::table('files')
                ->whereIn('id', $chunk)
                ->where('purpose', '!=', FilePurpose::Signature->value)
                ->pluck('id');

            foreach ($ids as $id) {
                $files[] = (string) $id;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The first active Admin by id that is not purged with the students (the new submitter of their lomba).
     *
     * @param  list<int>  $users
     */
    private function adminId(array $users): ?int
    {
        $tables = (array) config('permission.table_names');
        $roleKey = (string) (config('permission.column_names.role_pivot_key') ?? 'role_id');
        $modelKey = (string) (config('permission.column_names.model_morph_key') ?? 'model_id');
        $pivot = $tables['model_has_roles'] ?? 'model_has_roles';

        $roleIds = DB::table($tables['roles'] ?? 'roles')->where('name', RoleName::Admin->value)->pluck('id');

        $admins = DB::table('users')
            ->join($pivot, "{$pivot}.{$modelKey}", '=', 'users.id')
            ->where("{$pivot}.model_type", (new User)->getMorphClass())
            ->whereIn("{$pivot}.{$roleKey}", $roleIds)
            ->where('users.status', UserStatus::Active->value)
            ->whereNull('users.deleted_at')
            ->orderBy('users.id')
            ->pluck('users.id');

        foreach ($admins as $id) {
            if (! in_array((int) $id, $users, true)) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Distinct values of $select of the rows whose $column is one of $ids.
     *
     * @param  list<string>|list<int>  $ids
     * @return list<string>
     */
    private function pluckIn(string $table, string $select, string $column, array $ids): array
    {
        $values = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (DB::table($table)->whereIn($column, $chunk)->distinct()->pluck($select) as $value) {
                $values[(string) $value] = true;
            }
        }

        $list = array_map(strval(...), array_keys($values));
        sort($list);

        return $list;
    }

    /** @param list<int> $ids */
    private function countIn(string $table, string $column, array $ids): int
    {
        $total = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $total += DB::table($table)->whereIn($column, $chunk)->count();
        }

        return $total;
    }

    /**
     * Non-empty string ids, each once, sorted.
     *
     * @param  array<int|string, mixed>  $studentIds
     * @return list<string>
     */
    private static function normalize(array $studentIds): array
    {
        $ids = [];

        foreach ($studentIds as $id) {
            if (is_string($id) && trim($id) !== '') {
                $ids[trim($id)] = true;
            }
        }

        $list = array_map(strval(...), array_keys($ids));
        sort($list);

        return $list;
    }

    /** @return array<string, int> */
    private static function emptyCounts(): array
    {
        return array_fill_keys(self::COUNT_KEYS, 0);
    }
}
