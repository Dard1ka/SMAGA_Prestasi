<?php

declare(strict_types=1);

namespace App\Actions\Students;

use App\Enums\FilePurpose;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * smaga_server, the lead's request of 4 Nov 2026 ("data siswa hapus semua, ingat cuman siswa"): removes every student
 * account and everything that hangs on a student (the trial data before the real students are imported), run by
 * `php artisan sth:siswa:hapus-semua`. A HARD delete: the classes become empty and every NISN is free again.
 *
 * Deleted:
 *  - every student account: users with the role `siswa` and users that have a `students` row (soft-deleted ones too),
 *    whatever their status; with them their `students` row, sessions, bell rows, role and permission rows, OTP codes,
 *    email notices, passkeys, password-reset rows, imports and staff profile;
 *  - every row that needs a student (all of them do, by their foreign keys): appreciations (the rows on an event; the
 *    events stay), publications, achievements, report files, reports, letters (revoked ones too), registration
 *    reminders, registration members, registrations, talent profile files, talent profiles, talent applications;
 *  - the `files` rows of those rows (letter PDFs, report evidence, talent evidence, talent profile files, old flyers)
 *    and the student-evidence files a deleted account owns that nothing points at any more (left behind by a report
 *    revision or a replaced talent evidence), each only when nothing that stays still points at it (a lomba's juknis
 *    or cover, an import, the signature setting) and never a signature image. Their bytes on the disk are deleted
 *    after the transaction committed.
 *
 * Kept: staff and guru accounts, classes, settings, the signature, weights, talent types, appreciation events, the
 * lomba and the audit log (append-only, never touched). A lomba a student proposed stays in the catalog with the first
 * active Admin (by id) as its submitter; without such an Admin the run is refused and writes nothing. Nullable user
 * columns of kept rows that name a deleted account become null (USER_REFERENCES_NULLED). A required user column of a
 * kept history row that names a deleted account (USER_REFERENCES_BLOCKING; cannot happen for a student) refuses the run.
 *
 * Everything is written in ONE transaction with ONE audit row `student.all_deleted` (log `account`, counts only), then
 * the bytes go and the public pages' cache is flushed. Query builder only: no model events, so no row-by-row audit.
 * Counts only: no name, NISN or email is ever returned, printed or logged. Idempotent: a second run finds nothing.
 *
 * A system Action like App\Actions\Accounts\ApplyBirthDateInitialPasswords: the operator runs it on the server, there is
 * no actor to authorize (the command refuses `--terapkan` outside APP_ENV=local without `--force`).
 */
final class DeleteAllStudents
{
    public const EVENT = 'student.all_deleted';

    /** Ids per whereIn() statement. */
    public const CHUNK = 500;

    /**
     * Every row of these tables needs a student (directly or through a registration, report or achievement), so they
     * are emptied, children first so no foreign key ever blocks a statement.
     */
    public const EMPTIED_TABLES = [
        'appreciations',
        'publications',
        'achievements',
        'report_files',
        'reports',
        'letters',
        // SV8, D-199: the revisions of the registrations (ids of students and accounts only).
        'registration_revisions',
        'registration_reminders',
        'registration_members',
        'registrations',
        'talent_profile_files',
        'talent_profiles',
        'talent_applications',
    ];

    /** File columns of the emptied rows: these files are deleted unless something that stays still points at them. */
    public const DELETED_FILE_REFERENCES = [
        ['letters', 'file_id'],
        ['report_files', 'file_id'],
        ['talent_applications', 'evidence_file_id'],
        ['talent_profile_files', 'file_id'],
        ['publications', 'flyer_file_id'],
    ];

    /**
     * File columns of the emptied rows whose files always stay: `letters.signature_file_id` is the signature image the
     * letter was signed with (the current one or an older one, kept as evidence by RemoveSignatureImage).
     */
    public const KEPT_FILES_OF_DELETED_ROWS = [
        ['letters', 'signature_file_id'],
    ];

    /** File columns of rows that stay: a file named here is never deleted (the signature setting is checked too). */
    public const KEPT_FILE_REFERENCES = [
        ['competitions', 'juknis_file_id'],
        ['competitions', 'cover_file_id'],
        ['student_imports', 'file_id'],
    ];

    /** Files a deleted account uploaded as student evidence go too when nothing points at them any more. */
    public const OWNED_PURPOSES = [FilePurpose::ReportEvidence, FilePurpose::TalentEvidence];

    /** Nullable user columns of rows that stay: emptied where they name a deleted account. */
    public const USER_REFERENCES_NULLED = [
        ['files', 'owner_user_id'],
        ['weight_versions', 'created_by'],
        ['competitions', 'verified_by'],
        ['competitions', 'default_mentor_id'],
        ['appreciation_events', 'completed_by'],
        ['principal_replacements', 'from_user_id'],
        ['principal_replacements', 'cancelled_by'],
        ['role_change_requests', 'approved_by'],
        ['role_change_requests', 'cancelled_by'],
    ];

    /** The lomba a deleted account proposed: the first active Admin becomes the submitter. */
    public const USER_REFERENCES_MOVED = [
        ['competitions', 'submitted_by'],
    ];

    /** Rows of a deleted account that go with it, in this order (`students` last: `users` needs it gone). */
    public const USER_ROWS_DELETED = [
        ['sessions', 'user_id'],
        ['otp_codes', 'user_id'],
        ['email_notices', 'user_id'],
        ['passkeys', 'user_id'],
        ['student_imports', 'user_id'],
        ['staff_profiles', 'user_id'],
        ['students', 'user_id'],
    ];

    /**
     * Required user columns of history rows that stay. A deleted account here (a student made Kepala Sekolah or given
     * a staff role request: never for a real student) refuses the run instead of breaking that history.
     */
    public const USER_REFERENCES_BLOCKING = [
        ['principal_replacements', 'to_user_id'],
        ['principal_replacements', 'requested_by'],
        ['role_change_requests', 'user_id'],
        ['role_change_requests', 'requested_by'],
    ];

    /**
     * What a run would delete, writing nothing.
     *
     * @return array{counts: array<string, int>, by_status: array<string, int>, kept: array<string, int>, refusal: ?string, nothing: bool}
     */
    public function preview(): array
    {
        return $this->summary($this->plan());
    }

    /**
     * Deletes everything in one transaction, then the bytes of the deleted files. `applied` is false when there was
     * nothing to delete or the run was refused (`refusal`); nothing was written then.
     *
     * @return array{counts: array<string, int>, by_status: array<string, int>, kept: array<string, int>, refusal: ?string, nothing: bool, applied: bool, disk_missing: int, disk_failed: int}
     */
    public function handle(): array
    {
        /** @var array{plan: array<string, mixed>, bytes: list<array{disk: string, path: string}>|null} $outcome */
        $outcome = DB::transaction(function (): array {
            $plan = $this->plan();

            if ($plan['refusal'] !== null || $plan['nothing']) {
                return ['plan' => $plan, 'bytes' => null];
            }

            $bytes = $this->delete($plan);

            activity('account')
                ->event(self::EVENT)
                ->withProperties([
                    'via' => 'cli',
                    ...$plan['counts'],
                    'per_status' => $plan['by_status'],
                ])
                ->log(self::EVENT);

            return ['plan' => $plan, 'bytes' => $bytes];
        });

        $result = $this->summary($outcome['plan']) + ['applied' => false, 'disk_missing' => 0, 'disk_failed' => 0];

        if ($outcome['bytes'] === null) {
            return $result;
        }

        // After the commit: a rolled-back run never loses a file it still points at.
        foreach ($outcome['bytes'] as $file) {
            try {
                $disk = Storage::disk($file['disk']);

                if (! $disk->exists($file['path'])) {
                    $result['disk_missing']++;

                    continue;
                }

                if (! $disk->delete($file['path'])) {
                    $result['disk_failed']++;
                }
            } catch (Throwable) {
                $result['disk_failed']++;
            }
        }

        PublicAchievements::flush();

        $result['applied'] = true;

        return $result;
    }

    /**
     * @return array{user_ids: list<int>, file_ids: list<string>, admin_id: ?int, counts: array<string, int>, by_status: array<string, int>, kept: array<string, int>, refusal: ?string, nothing: bool}
     */
    private function plan(): array
    {
        $users = $this->studentAccountIds();
        $fileIds = $this->filesToDelete($users);

        $counts = [
            'siswa' => $this->countIn('users', 'id', $users),
            'pendaftaran' => DB::table('registrations')->count(),
            'anggota' => DB::table('registration_members')->count(),
            'surat' => DB::table('letters')->count(),
            'laporan' => DB::table('reports')->count(),
            'prestasi' => DB::table('achievements')->count(),
            'publikasi' => DB::table('publications')->count(),
            'apresiasi' => DB::table('appreciations')->count(),
            'pilihan_talenta' => DB::table('talent_applications')->count(),
            'profil_talenta' => DB::table('talent_profiles')->count(),
            'file' => count($fileIds),
            'lomba_ke_admin' => $this->countIn('competitions', 'submitted_by', $users),
        ];

        $adminId = $this->adminId($users);
        $blocking = 0;

        foreach (self::USER_REFERENCES_BLOCKING as [$table, $column]) {
            $blocking += $this->countIn($table, $column, $users);
        }

        $refusal = match (true) {
            $blocking > 0 => "{$blocking} baris riwayat penggantian Kepala Sekolah atau perubahan peran menunjuk akun siswa.",
            $counts['lomba_ke_admin'] > 0 && $adminId === null => "Tidak ada akun Admin aktif yang bisa menjadi pengusul {$counts['lomba_ke_admin']} lomba usulan siswa.",
            default => null,
        };

        $staff = DB::table('users')->whereNull('deleted_at')->count();

        foreach (array_chunk($users, self::CHUNK) as $chunk) {
            $staff -= DB::table('users')->whereNull('deleted_at')->whereIn('id', $chunk)->count();
        }

        return [
            'user_ids' => $users,
            'file_ids' => $fileIds,
            'admin_id' => $adminId,
            'counts' => $counts,
            'by_status' => $this->byStatus($users),
            'kept' => [
                'staf' => $staff,
                'kelas' => DB::table('classrooms')->count(),
                'lomba' => DB::table('competitions')->whereNull('deleted_at')->count(),
                'acara_apresiasi' => DB::table('appreciation_events')->count(),
            ],
            'refusal' => $refusal,
            'nothing' => $users === [] && array_sum($counts) === 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{counts: array<string, int>, by_status: array<string, int>, kept: array<string, int>, refusal: ?string, nothing: bool}
     */
    private function summary(array $plan): array
    {
        return [
            'counts' => $plan['counts'],
            'by_status' => $plan['by_status'],
            'kept' => $plan['kept'],
            'refusal' => $plan['refusal'],
            'nothing' => $plan['nothing'],
        ];
    }

    /**
     * The writes, in foreign-key order. Returns disk + path of every deleted file row.
     *
     * @param  array{user_ids: list<int>, file_ids: list<string>, admin_id: ?int}  $plan
     * @return list<array{disk: string, path: string}>
     */
    private function delete(array $plan): array
    {
        $users = $plan['user_ids'];

        // 1. Everything that needs a student. A replacement letter points at the letter it replaced: unlink first.
        DB::table('letters')->whereNotNull('replaces_letter_id')->update(['replaces_letter_id' => null]);

        foreach (self::EMPTIED_TABLES as $table) {
            DB::table($table)->delete();
        }

        // 2. Rows that stay but name a deleted account.
        foreach (array_chunk($users, self::CHUNK) as $chunk) {
            foreach (self::USER_REFERENCES_MOVED as [$table, $column]) {
                DB::table($table)->whereIn($column, $chunk)->update([$column => $plan['admin_id']]);
            }

            foreach (self::USER_REFERENCES_NULLED as [$table, $column]) {
                DB::table($table)->whereIn($column, $chunk)->update([$column => null]);
            }
        }

        // 3. The files nothing that stays points at.
        $bytes = [];

        foreach (array_chunk($plan['file_ids'], self::CHUNK) as $chunk) {
            foreach (DB::table('files')->whereIn('id', $chunk)->get(['disk', 'path']) as $file) {
                $bytes[] = ['disk' => (string) $file->disk, 'path' => (string) $file->path];
            }

            DB::table('files')->whereIn('id', $chunk)->delete();
        }

        // 4. The accounts.
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
                DB::table($table)->whereIn($column, $chunk)->delete();
            }

            DB::table('users')->whereIn('id', $chunk)->delete();
        }

        return $bytes;
    }

    /**
     * Users with the role siswa and users with a `students` row (soft-deleted rows and soft-deleted users included).
     *
     * @return list<int>
     */
    private function studentAccountIds(): array
    {
        $tables = (array) config('permission.table_names');
        $roleKey = (string) (config('permission.column_names.role_pivot_key') ?? 'role_id');
        $modelKey = (string) (config('permission.column_names.model_morph_key') ?? 'model_id');

        $roleIds = DB::table($tables['roles'] ?? 'roles')->where('name', RoleName::Siswa->value)->pluck('id');

        return DB::table($tables['model_has_roles'] ?? 'model_has_roles')
            ->where('model_type', (new User)->getMorphClass())
            ->whereIn($roleKey, $roleIds)
            ->pluck($modelKey)
            ->merge(DB::table('students')->pluck('user_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The first active Admin by id that is not deleted with the students (the new submitter of their lomba).
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
     * Files of the deleted rows plus student evidence a deleted account owns, minus every file something that stays
     * points at, minus every signature image.
     *
     * @param  list<int>  $users
     * @return list<string>
     */
    private function filesToDelete(array $users): array
    {
        $candidates = [];

        foreach (self::DELETED_FILE_REFERENCES as [$table, $column]) {
            foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $id) {
                $candidates[(string) $id] = true;
            }
        }

        $purposes = array_map(fn (FilePurpose $purpose): string => $purpose->value, self::OWNED_PURPOSES);

        foreach (array_chunk($users, self::CHUNK) as $chunk) {
            foreach (DB::table('files')->whereIn('owner_user_id', $chunk)->whereIn('purpose', $purposes)->pluck('id') as $id) {
                $candidates[(string) $id] = true;
            }
        }

        foreach ([...self::KEPT_FILE_REFERENCES, ...self::KEPT_FILES_OF_DELETED_ROWS] as [$table, $column]) {
            foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $id) {
                unset($candidates[(string) $id]);
            }
        }

        $signature = Setting::get(SettingKey::SignatureFileId);

        if (is_string($signature) && $signature !== '') {
            unset($candidates[$signature]);
        }

        $files = [];

        foreach (array_chunk(array_keys($candidates), self::CHUNK) as $chunk) {
            $kept = DB::table('files')
                ->whereIn('id', $chunk)
                ->where('purpose', '!=', FilePurpose::Signature->value)
                ->pluck('id');

            foreach ($kept as $id) {
                $files[] = (string) $id;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  list<int>  $users
     * @return array<string, int> UserStatus value => accounts, in the enum's order, statuses without accounts left out
     */
    private function byStatus(array $users): array
    {
        $totals = [];

        foreach (array_chunk($users, self::CHUNK) as $chunk) {
            $rows = DB::table('users')->whereIn('id', $chunk)->selectRaw('status, count(*) as total')->groupBy('status')->get();

            foreach ($rows as $row) {
                $totals[(string) $row->status] = ($totals[(string) $row->status] ?? 0) + (int) $row->total;
            }
        }

        $ordered = [];

        foreach (UserStatus::cases() as $status) {
            if (($totals[$status->value] ?? 0) > 0) {
                $ordered[$status->value] = $totals[$status->value];
                unset($totals[$status->value]);
            }
        }

        return $ordered + $totals;
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
}
